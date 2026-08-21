<?php

namespace App\Services\Broadcasting;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;

/**
 * مزوّد البثّ الفعّال — Pusher أو Reverb — ومصدر إعداده الوحيد.
 *
 * كانت ثلاثة مواضع تكتب إعداد البثّ كاملاً وقت التشغيل، كلٌّ بنسخته:
 * WhatsappService و WebhookController و PayPalService. وكلّها تكتب المفاتيح
 * وحدها فتمسح host و port و scheme من الإعداد الأصلي — فيعود البثّ إلى سحابة
 * Pusher مهما ضُبط في .env. تبديل الوجهة كان سيبدو ناجحاً على الصفحات العادية
 * ويفشل صامتاً في المحادثات والويب هوك.
 *
 * صارت الثلاثة تستدعي apply() فتتشارك مصدراً واحداً يحمل الإعداد كاملاً.
 *
 * والمزوّدان مُعرَّفان معاً ولا يُمسح أحدهما: التبديل بينهما قيمة واحدة في
 * جدول الإعدادات، فالعودة عند أي خلل ثوانٍ بلا نشر ولا إدخال مفاتيح.
 *
 * Reverb يتكلّم بروتوكول Pusher، فكلاهما يستعمل سائق pusher نفسه ولا يحتاج
 * التطبيق حزمة laravel/reverb — تلك لتشغيل الخادم، وخادمنا منفصل.
 */
class BroadcastProvider
{
    public const PUSHER = 'pusher';
    public const REVERB = 'reverb';

    /** مفتاح الإعداد الذي يحمل اسم المزوّد الفعّال. */
    public const SETTING_KEY = 'broadcast_provider';

    /**
     * فتحة الإعداد التي يقرأها Laravel. اسمها pusher تاريخياً وتحمل إعداد
     * المزوّد الفعّال أيّاً كان — تغيير الاسم كان سيلزم تتبّع كل مستهلك.
     */
    private const SLOT = 'broadcasting.connections.pusher';

    /** @var array<string, string>|null إعدادات مقروءة مرّة واحدة لكل طلب. */
    private static ?array $settings = null;

    /**
     * اسم المزوّد الفعّال. الافتراضي pusher: أي بيئة لم تُضبط بعد تبقى على ما
     * كانت عليه، ولا يُبدَّل شيء بمجرّد نشر هذا الكود.
     */
    public static function active(): string
    {
        $value = strtolower(trim((string) (self::settings()[self::SETTING_KEY] ?? '')));

        return $value === self::REVERB ? self::REVERB : self::PUSHER;
    }

    /**
     * إعداد الاتصال كاملاً للمزوّد الفعّال.
     *
     * قيم جدول الإعدادات تسبق قيم .env: لوحة الأدمن هي ما يعدّله المشغّل، وترك
     * .env يفوز كان يجعل تعديله من اللوحة بلا أثر.
     *
     * @return array<string, mixed>
     */
    public static function connection(): array
    {
        return self::active() === self::REVERB
            ? self::reverbConnection()
            : self::pusherConnection();
    }

    /**
     * تطبيق الإعداد على وقت التشغيل. تستدعيها المواضع التي كانت تكتبه بنفسها.
     */
    public static function apply(): void
    {
        Config::set(self::SLOT, self::connection());
    }

    /**
     * ما تحتاجه الواجهة للاشتراك: المفتاح والعنوان لا السرّ.
     *
     * @return array{provider: string, key: ?string, cluster: ?string, host: ?string, port: int, scheme: string, force_tls: bool}
     */
    public static function clientConfig(): array
    {
        $connection = self::connection();
        $options = $connection['options'] ?? [];
        $scheme = (string) ($options['scheme'] ?? 'https');

        return [
            'provider' => self::active(),
            'key' => $connection['key'] ?? null,
            'cluster' => $options['cluster'] ?? null,
            // Pusher السحابي يشتقّ عنوانه من التجميعة، فنُرجع null ليستعمل
            // العميل سلوكه الافتراضي بدل عنوان مُخترَع.
            'host' => self::active() === self::REVERB ? ($options['host'] ?? null) : null,
            'port' => (int) ($options['port'] ?? 443),
            'scheme' => $scheme,
            'force_tls' => $scheme === 'https',
        ];
    }

    /** يُستدعى في الاختبارات وبعد تعديل الإعدادات من اللوحة. */
    public static function forget(): void
    {
        self::$settings = null;
    }

    /** @return array<string, mixed> */
    private static function pusherConnection(): array
    {
        $cluster = self::setting('pusher_app_cluster', (string) env('PUSHER_APP_CLUSTER', 'mt1'));
        $host = (string) env('PUSHER_HOST') ?: 'api-' . $cluster . '.pusher.com';
        $scheme = (string) env('PUSHER_SCHEME', 'https');

        return [
            'driver' => 'pusher',
            'key' => self::setting('pusher_app_key', (string) env('PUSHER_APP_KEY')),
            'secret' => self::setting('pusher_app_secret', (string) env('PUSHER_APP_SECRET')),
            'app_id' => self::setting('pusher_app_id', (string) env('PUSHER_APP_ID')),
            'options' => [
                'cluster' => $cluster,
                // العنوان والمنفذ يبقيان: إغفالهما هو ما كان يُعيد الوجهة إلى
                // السحابة عند كل إعادة كتابة للإعداد.
                'host' => $host,
                'port' => (int) env('PUSHER_PORT', 443),
                'scheme' => $scheme,
                'encrypted' => true,
                'useTLS' => $scheme === 'https',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function reverbConnection(): array
    {
        $scheme = self::setting('reverb_scheme', (string) env('REVERB_SCHEME', 'https'));

        return [
            'driver' => 'pusher',
            'key' => self::setting('reverb_app_key', (string) env('REVERB_APP_KEY')),
            'secret' => self::setting('reverb_app_secret', (string) env('REVERB_APP_SECRET')),
            'app_id' => self::setting('reverb_app_id', (string) env('REVERB_APP_ID')),
            'options' => [
                // Reverb لا يعرف التجميعات، لكن مكتبة pusher تشترط وجود المفتاح.
                'cluster' => 'mt1',
                'host' => self::setting('reverb_host', (string) env('REVERB_HOST')),
                'port' => (int) self::setting('reverb_port', (string) env('REVERB_PORT', 443)),
                'scheme' => $scheme,
                'encrypted' => true,
                'useTLS' => $scheme === 'https',
            ],
        ];
    }

    private static function setting(string $key, string $fallback = ''): string
    {
        $value = trim((string) (self::settings()[$key] ?? ''));

        return $value !== '' ? $value : $fallback;
    }

    /**
     * قراءة واحدة لكل طلب: هذه الدالّة تُستدعى مع كل إنشاء لخدمة واتساب،
     * واستعلام لكل استدعاء كان يُضاعف الحمل بلا فائدة.
     *
     * @return array<string, string>
     */
    private static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        try {
            return self::$settings = Setting::whereIn('key', [
                self::SETTING_KEY,
                'pusher_app_key', 'pusher_app_secret', 'pusher_app_id', 'pusher_app_cluster',
                'reverb_app_key', 'reverb_app_secret', 'reverb_app_id',
                'reverb_host', 'reverb_port', 'reverb_scheme',
            ])->pluck('value', 'key')->all();
        } catch (\Throwable $e) {
            // قبل الترحيلات أو حين تتعذّر القاعدة: نرجع إلى .env بدل أن يسقط
            // الطلب. البثّ ميزة مساعدة ولا يصحّ أن يُسقط ما حوله.
            return self::$settings = [];
        }
    }
}
