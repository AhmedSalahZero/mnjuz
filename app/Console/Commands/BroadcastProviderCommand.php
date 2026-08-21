<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Console\Command;
use Pusher\Pusher;
use Throwable;

/**
 * عرض مزوّد البثّ الفعّال والتبديل بينه وبين الآخر.
 *
 * التبديل قيمة واحدة في جدول الإعدادات، لكن معرفة «أين نحن الآن» كانت تتطلّب
 * قراءة الجدول يدوياً واستحضار أيّ المفاتيح يقرؤها الكود — وهذا بالضبط ما
 * يجعل التبديل مخيفاً وقت العطل. هذا الأمر يجعل الحالة والتبديل والتحقّق
 * ثلاثتها في مكان واحد.
 *
 * التبديل الأعمى هو الخطر الحقيقي: وجهة مفاتيحها ناقصة تقبل التبديل ثم تفشل
 * صامتة — تُحفظ الرسائل ولا تصل لحظياً، فيبدو النظام بطيئاً لا معطّلاً. لذلك
 * يرفض الأمر التبديل إلى وجهة ناقصة الإعداد، ويبثّ حدثاً حقيقياً قبل اعتمادها
 * ما لم يُطلب خلاف ذلك.
 */
class BroadcastProviderCommand extends Command
{
    protected $signature = 'broadcast:provider
        {provider? : pusher أو reverb — يُترك فارغاً لعرض الحالة فقط}
        {--test : بثّ حدث حقيقي للتحقّق من الوجهة}
        {--no-test : التبديل بلا تحقّق مسبق}
        {--force : التبديل رغم نقص الإعداد أو فشل التحقّق}';

    protected $description = 'عرض مزوّد البثّ الفعّال (Pusher / Reverb) والتبديل بينهما';

    public function handle(): int
    {
        BroadcastProvider::forget();

        $target = $this->argument('provider');

        if ($target === null) {
            $this->status();

            return $this->option('test') ? $this->probe(BroadcastProvider::active()) : self::SUCCESS;
        }

        return $this->switchTo(strtolower(trim($target)));
    }

    // ----------------------------------------------------------- الحالة

    private function status(): void
    {
        $stored = $this->storedProvider();
        $active = BroadcastProvider::active();

        $this->newLine();

        // القاعدة هي مصدر الحقيقة؛ عند تعذّرها يرتدّ الكود إلى .env بصمت.
        // إعلان مزوّد في تلك الحالة ادّعاءٌ لا معرفة — والتشخيص وقت العطل هو
        // بالضبط حين تكون القاعدة متعذّرة.
        if ($stored === false) {
            $this->error('  تعذّر الوصول إلى قاعدة البيانات — لا يمكن معرفة المزوّد الفعّال.');
            $this->line('  ما يلي مقروء من .env فقط، وقد لا يطابق ما يعمل به الخادم.');
        } else {
            $this->line('  المزوّد الفعّال: <options=bold;fg=cyan>' . strtoupper($active) . '</>');
        }

        $this->newLine();

        $rows = [];
        foreach ([BroadcastProvider::PUSHER, BroadcastProvider::REVERB] as $provider) {
            $connection = $this->connectionFor($provider);
            $missing = $this->missingKeys($connection);

            $rows[] = [
                ($stored !== false && $provider === $active) ? '◀ ' . $provider : '  ' . $provider,
                $this->destination($connection),
                $this->mask($connection['key'] ?? ''),
                $missing === [] ? '✅ مكتمل' : '⚠️  ينقصه: ' . implode('، ', $missing),
            ];
        }

        $this->table(['المزوّد', 'الوجهة', 'المفتاح', 'الإعداد'], $rows);

        // غياب الصفّ يعني أننا على الافتراضي لا على اختيار صريح — فرقٌ يهمّ
        // عند تشخيص عطل.
        if ($stored === null) {
            $this->warn('  لا يوجد صفّ broadcast_provider في جدول الإعدادات — نعمل على الافتراضي (pusher).');
            $this->line('  شغّل <options=bold>php artisan migrate</> لإنشائه.');
            $this->newLine();
        }
    }

    // ---------------------------------------------------------- التبديل

    private function switchTo(string $target): int
    {
        if (!in_array($target, [BroadcastProvider::PUSHER, BroadcastProvider::REVERB], true)) {
            $this->error('مزوّد غير معروف: ' . $target . ' — المتاح: pusher أو reverb');

            return self::FAILURE;
        }

        if ($this->storedProvider() === false) {
            $this->error('تعذّر الوصول إلى قاعدة البيانات — لا تبديل بلا معرفة نقطة الانطلاق.');

            return self::FAILURE;
        }

        $current = BroadcastProvider::active();

        if ($target === $current) {
            $this->info('نحن على ' . $target . ' أصلاً — لا تغيير.');
            $this->status();

            return self::SUCCESS;
        }

        $connection = $this->connectionFor($target);
        $missing = $this->missingKeys($connection);

        if ($missing !== [] && !$this->option('force')) {
            $this->error('لا يمكن التبديل إلى ' . $target . ': ينقصه ' . implode('، ', $missing));
            $this->line('  اضبط الصفوف الناقصة في جدول الإعدادات، أو استعمل --force للتبديل رغم ذلك.');

            return self::FAILURE;
        }

        // التحقّق قبل التبديل لا بعده: وجهة معطوبة تُكتشف والبثّ ما يزال يعمل.
        if (!$this->option('no-test')) {
            $this->line('  التحقّق من ' . $target . ' قبل التبديل…');

            if ($this->probe($target) !== self::SUCCESS && !$this->option('force')) {
                $this->error('لم يُبدَّل شيء — ما زلنا على ' . $current . '.');
                $this->line('  استعمل --force للتبديل رغم فشل التحقّق، أو --no-test لتخطّيه.');

                return self::FAILURE;
            }
        }

        try {
            Setting::updateOrCreate(
                ['key' => BroadcastProvider::SETTING_KEY],
                ['value' => $target]
            );
        } catch (Throwable $e) {
            $this->error('فشل حفظ التبديل: ' . $e->getMessage());
            $this->line('  ما زلنا على ' . $current . '.');

            return self::FAILURE;
        }

        BroadcastProvider::forget();

        $this->newLine();
        $this->info('  ✅ ' . $current . ' ← ' . strtoupper($target));
        $this->line('  الوجهة: ' . $this->destination($this->connectionFor($target)));
        $this->newLine();

        // العامل يقرأ الإعدادات قبل كل وظيفة، فلا يحتاج إعادة تشغيل. يبقى ما
        // لا نملك تحديثه: صفحة مفتوحة بنت اتصالها عند تحميلها.
        $this->line('  عمّال الطابور يتبعون التبديل تلقائياً — لا حاجة إلى إعادة تشغيل.');
        $this->line('  المتصفّحات المفتوحة تحتاج إعادة تحميل الصفحة، والتطبيق يلتقطه عند الفتح التالي.');
        $this->newLine();

        return self::SUCCESS;
    }

    // ---------------------------------------------------------- التحقّق

    /** بثّ حدث حقيقي عبر إعداد المزوّد المطلوب. */
    private function probe(string $provider): int
    {
        $connection = $this->connectionFor($provider);
        $options = $connection['options'] ?? [];

        $this->line('  → ' . $this->destination($connection));

        try {
            $pusher = new Pusher(
                (string) ($connection['key'] ?? ''),
                (string) ($connection['secret'] ?? ''),
                (string) ($connection['app_id'] ?? ''),
                [
                    'cluster' => $options['cluster'] ?? 'mt1',
                    'host' => $options['host'] ?? null,
                    'port' => (int) ($options['port'] ?? 443),
                    'scheme' => $options['scheme'] ?? 'https',
                    'useTLS' => (bool) ($options['useTLS'] ?? true),
                    'encrypted' => true,
                    'timeout' => 10,
                ]
            );

            $pusher->trigger('mnjz-health', 'BroadcastProviderCheck', [
                'provider' => $provider,
                'at' => now()->toIso8601String(),
            ]);

            $this->info('  ✅ ' . $provider . ' قَبِل البثّ.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('  ❌ ' . $provider . ' رفض البثّ: ' . $this->cleanError($e->getMessage()));

            return self::FAILURE;
        }
    }

    // --------------------------------------------------------- مساعدات

    /**
     * القيمة المحفوظة كما هي: اسم المزوّد، أو null إن لم يوجد الصفّ، أو false
     * إن تعذّرت القاعدة. الثلاثة أحوال مختلفة ولا يصحّ خلطها في «pusher».
     *
     * @return string|null|false
     */
    private function storedProvider()
    {
        try {
            $value = Setting::where('key', BroadcastProvider::SETTING_KEY)->value('value');
        } catch (Throwable $e) {
            return false;
        }

        return trim((string) $value) === '' ? null : trim((string) $value);
    }

    /** @return array<string, mixed> */
    private function connectionFor(string $provider): array
    {
        return BroadcastProvider::connectionFor($provider);
    }

    /**
     * المفاتيح الناقصة. السرّ ضمنها: بدونه يُبنى العميل ثم يُرفض كل بثّ.
     *
     * @param  array<string, mixed>  $connection
     * @return array<int, string>
     */
    private function missingKeys(array $connection): array
    {
        $missing = [];

        foreach (['key' => 'المفتاح', 'secret' => 'السرّ', 'app_id' => 'المعرّف'] as $field => $label) {
            if (trim((string) ($connection[$field] ?? '')) === '') {
                $missing[] = $label;
            }
        }

        if (trim((string) ($connection['options']['host'] ?? '')) === '') {
            $missing[] = 'العنوان';
        }

        return $missing;
    }

    /** @param array<string, mixed> $connection */
    private function destination(array $connection): string
    {
        $options = $connection['options'] ?? [];
        $host = trim((string) ($options['host'] ?? ''));

        if ($host === '') {
            return '—';
        }

        return ($options['scheme'] ?? 'https') . '://' . $host . ':' . (int) ($options['port'] ?? 443);
    }

    /**
     * رسالة الخطأ بلا الرابط الموقّع.
     *
     * مكتبة pusher تُلحق الرابط كاملاً بكل خطأ، وفيه auth_key و auth_signature.
     * التوقيع لا يُعكَس إلى السرّ، لكن سطر الطرفية يُنسَخ في التذاكر ويُحفظ في
     * السجلّات — ولا فائدة تشخيصية في عرضه.
     */
    private function cleanError(string $message): string
    {
        $message = (string) preg_replace('/\?auth_key=\S*/', '', $message);

        return trim((string) preg_replace('/\s+for\s+https?:\/\/\S*$/', '', $message));
    }

    /** المفتاح ليس سرّاً لكنّه يُميّز البيئات؛ نعرض طرفيه ليُتعرَّف عليه بلا نسخه. */
    private function mask(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '—';
        }

        return strlen($value) <= 8
            ? str_repeat('•', strlen($value))
            : substr($value, 0, 4) . str_repeat('•', 4) . substr($value, -4);
    }
}
