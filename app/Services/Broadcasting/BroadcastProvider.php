<?php

namespace App\Services\Broadcasting;

use App\Models\Setting;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

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
 *
 * ولكل منشأة مزوّدها (organizations.broadcast_provider) يختاره الأدمن، وما لم
 * يُختَر يتبع افتراضي المنصّة. الأحداث تُبثّ عبر اتصالٍ مسمّى لكل مزوّد
 * (provider_pusher / provider_reverb) فتصل كل منشأة إلى مزوّدها في العامل
 * نفسه، والمصادقة على القنوات تُوقَّع بسرّ مزوّد المنشأة صاحبة القناة.
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

    /** بادئة اتصالَي المزوّدين المسمَّيين: provider_pusher و provider_reverb. */
    private const CONNECTION_PREFIX = 'provider_';

    /** @var array<string, string>|null إعدادات مقروءة مرّة واحدة لكل طلب. */
    private static ?array $settings = null;

    /** @var array<int, ?string> اختيار كل منشأة مقروءاً مرّة لكل طلب؛ null = الافتراضي. */
    private static array $organizations = [];

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
     * مزوّد المنشأة: ما اختاره لها الأدمن، وإلا افتراضي المنصّة. بلا منشأة
     * (صفحات الأدمن، أو تعذّر معرفتها) فالافتراضي.
     */
    public static function forOrganization(?int $organizationId): string
    {
        if (!$organizationId) {
            return self::active();
        }

        if (!array_key_exists($organizationId, self::$organizations)) {
            try {
                $value = DB::table('organizations')->where('id', $organizationId)->value('broadcast_provider');
            } catch (\Throwable $e) {
                // قبل الترحيل (لا عمود) أو حين تتعذّر القاعدة: الافتراضي.
                $value = null;
            }

            self::$organizations[$organizationId] = self::normalize($value);
        }

        return self::$organizations[$organizationId] ?? self::active();
    }

    /** pusher أو reverb، أو null لقيمة فارغة أو غير معروفة. */
    public static function normalize($value): ?string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, [self::PUSHER, self::REVERB], true) ? $value : null;
    }

    /** اسم الاتصال المسمّى لمزوّد: provider_pusher أو provider_reverb. */
    public static function connectionName(string $provider): string
    {
        return self::CONNECTION_PREFIX . (self::normalize($provider) ?? self::PUSHER);
    }

    /** الاتصال الذي تُبثّ عبره أحداث المنشأة. */
    public static function connectionNameForOrganization(?int $organizationId): string
    {
        return self::connectionName(self::forOrganization($organizationId));
    }

    /**
     * رقم المنشأة من اسم القناة. القنوات كلها تحمله: chats.ch{org}.{user}
     * و payments.ch{org} و contact.chat.deleted.{org}، بالبادئة أو بلاها.
     */
    public static function organizationFromChannel(string $channel): ?int
    {
        $channel = preg_replace('/^(presence-|private-)/', '', trim($channel));

        foreach (['/^chats\.ch(\d+)\./', '/^payments\.ch(\d+)$/', '/^contact\.chat\.deleted\.(\d+)$/'] as $pattern) {
            if (preg_match($pattern, (string) $channel, $match)) {
                return (int) $match[1];
            }
        }

        return null;
    }

    /**
     * المفاتيح الناقصة لمزوّد: key و secret و app_id و host.
     *
     * @return string[]
     */
    public static function missingCredentials(string $provider): array
    {
        $connection = self::connectionFor($provider);
        $missing = [];

        foreach (['key', 'secret', 'app_id'] as $field) {
            if (trim((string) ($connection[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        if (trim((string) ($connection['options']['host'] ?? '')) === '') {
            $missing[] = 'host';
        }

        return $missing;
    }

    /** مزوّدٌ مكتمل البيانات يصحّ التبديل إليه. */
    public static function isConfigured(string $provider): bool
    {
        return self::missingCredentials($provider) === [];
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
        return self::connectionFor(self::active());
    }

    /**
     * إعداد مزوّد بعينه لا الفعّال — للعرض والمقارنة والتحقّق قبل التبديل.
     *
     * بلا هذه الدالّة كان لا بدّ من تبديل الصفّ مؤقتاً للاطّلاع على الوجهة
     * الأخرى، وأي انقطاع بين الكتابتين يترك النظام مبدَّلاً بلا قصد.
     *
     * @return array<string, mixed>
     */
    public static function connectionFor(string $provider): array
    {
        return strtolower(trim($provider)) === self::REVERB
            ? self::reverbConnection()
            : self::pusherConnection();
    }

    /**
     * تطبيق الإعداد على وقت التشغيل. تستدعيها المواضع التي كانت تكتبه بنفسها.
     *
     * ولا يكفي ضبط الإعداد: مدير البثّ يبني عميل Pusher عند أوّل استعمال
     * ويحتفظ به، فأي تغيير بعده يُتجاهَل بصمت. في php-fpm لا يظهر الأثر لأن كل
     * طلب عملية جديدة، أمّا عامل الطابور فعملية طويلة العمر — يبني العميل مرّة
     * ويظلّ يبثّ إلى الوجهة القديمة مهما بُدّل المزوّد. لذلك نُسقط العميل
     * المحفوظ كلّما تغيّر الإعداد فعلاً.
     */
    public static function apply(): void
    {
        self::write(self::SLOT, 'pusher', self::connection());

        // اتصالا المزوّدين المسمَّيان معرَّفان دائماً: تختار الأحداث بينهما
        // بحسب منشأتها (broadcastConnections).
        foreach ([self::PUSHER, self::REVERB] as $provider) {
            $name = self::connectionName($provider);
            self::write('broadcasting.connections.' . $name, $name, self::connectionFor($provider));
        }
    }

    /**
     * المصادقة على قناة منشأة: الفتحة الافتراضية ومُذيعها يشيران إلى مزوّدها
     * طوال هذا الطلب. تسجيلات القنوات على المُذيع الافتراضي وحده، فهو من
     * يُوقّع — والتوقيع بسرّ مزوّدٍ آخر يرفضه خادم البثّ فيفشل الاشتراك بصمت.
     */
    public static function useForOrganization(?int $organizationId): void
    {
        self::write(self::SLOT, 'pusher', self::connectionFor(self::forOrganization($organizationId)));
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private static function write(string $configKey, string $driverName, array $connection): void
    {
        // مقارنة قبل الكتابة: بلا تغيير لا نُسقط عميلاً يعمل — والدالّة
        // تُستدعى مع كل إنشاء لخدمة واتساب.
        if (Config::get($configKey) === $connection) {
            return;
        }

        Config::set($configKey, $connection);

        self::refreshDriver($driverName, $connection);
    }

    /**
     * تحديث وجهة المُذيع المحفوظ — بتبديل عميله لا بإسقاطه.
     *
     * الإسقاط كان يبدو أنظف وكان عطلاً: Broadcast::channel تُسجّل تفويض القنوات
     * على كائن المُذيع نفسه، و BroadcastServiceProvider يفعل ذلك عند الإقلاع
     * قبل هذا المزوّد. فإسقاط المُذيع يمحو التسجيلات معه، فلا تُطابق أي قناة
     * ويردّ /broadcasting/auth بـ403 — والمتصفّح يفشل اشتراكه بصمت: تُحفظ
     * الرسائل ولا تصل لحظياً.
     *
     * تبديل العميل داخله يُبقي التسجيلات ويغيّر الوجهة.
     *
     * @param  array<string, mixed>  $connection
     */
    private static function refreshDriver(string $name, array $connection): void
    {
        try {
            $manager = app(BroadcastingFactory::class);

            // لا نطلب المُذيع إن لم يُنشأ بعد: إنشاؤه هنا قد يسبق تسجيل السائق
            // المخصّص فيُحفَظ مُذيع بلا إعادة المحاولة. وغيابه لا يضرّ أصلاً —
            // سيُبنى لاحقاً بالإعداد الذي كُتب للتوّ.
            $drivers = new \ReflectionProperty($manager, 'drivers');
            $drivers->setAccessible(true);
            $driver = ($drivers->getValue($manager))[$name] ?? null;

            if ($driver instanceof PusherBroadcaster) {
                $driver->setPusher($manager->pusher($connection));
            }
        } catch (\Throwable $e) {
            // البثّ ميزة مساعدة: تعذّر التحديث لا يصحّ أن يُسقط ما حوله.
        }
    }

    /**
     * ما تحتاجه الواجهة للاشتراك: المفتاح والعنوان لا السرّ.
     *
     * @return array{provider: string, key: ?string, cluster: ?string, host: ?string, port: int, scheme: string, force_tls: bool}
     */
    public static function clientConfig(?int $organizationId = null): array
    {
        $provider = self::forOrganization($organizationId);
        $connection = self::connectionFor($provider);
        $options = $connection['options'] ?? [];
        $scheme = (string) ($options['scheme'] ?? 'https');

        return [
            'provider' => $provider,
            'key' => $connection['key'] ?? null,
            'cluster' => $options['cluster'] ?? null,
            // Pusher السحابي يشتقّ عنوانه من التجميعة، فنُرجع null ليستعمل
            // العميل سلوكه الافتراضي بدل عنوان مُخترَع.
            'host' => $provider === self::REVERB ? ($options['host'] ?? null) : null,
            'port' => (int) ($options['port'] ?? 443),
            'scheme' => $scheme,
            'force_tls' => $scheme === 'https',
        ];
    }

    /** يُستدعى في الاختبارات وبعد تعديل الإعدادات من اللوحة. */
    public static function forget(): void
    {
        self::$settings = null;
        self::$organizations = [];
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
