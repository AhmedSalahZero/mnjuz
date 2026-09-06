<?php

namespace Tests\Feature;

use App\Helpers\LocaleVersion;
use Tests\TestCase;

/**
 * عطلان ظهرا معاً في الإنتاج، وكلاهما «يفشل بصمت»:
 *
 * ١) إرسال وسائط تفشل، فتُعاد المحاولة، فتجد الملف المؤقّت ممسوحاً قبل
 *    الإرسال فتنصرف عند أوّل سطر وتُعدّ ناجحة — الرسالة تضيع والرمي المقصود
 *    ليُظهر الفشل يصير بلا فائدة.
 *
 * ٢) مفتاح ترجمة جديد يظهر خاماً عند العميل — «{count} files» بدل «3 ملفات» —
 *    لأن الواجهة تحتفظ بالترجمات يوماً كاملاً بلا فحص للنسخة.
 */
class MediaRetryAndLocaleVersionTest extends TestCase
{
    // ------------------------------------------------ إعادة المحاولة

    /** الملف المؤقّت لا يُمسح إلا بعد نجاح الإرسال. */
    public function test_the_temp_file_survives_a_failed_send(): void
    {
        $job = file_get_contents(base_path('app/Jobs/SendMediaJob.php'));

        $sendPosition = strpos($job, '$response = $whatsappService->sendMedia(');
        $this->assertNotFalse($sendPosition, 'استدعاء الإرسال غير موجود');

        $beforeSend = substr($job, 0, $sendPosition);

        $this->assertStringNotContainsString(
            "Storage::disk('local')->delete(\$this->tempFilePath)",
            $beforeSend,
            'مسحُ الملف قبل الإرسال يجعل إعادة المحاولة تنصرف صامتة'
        );
    }

    /** ومع ذلك لا يتراكم على القرص: كل مخرج آخر يمسحه. */
    public function test_every_other_exit_cleans_the_temp_file(): void
    {
        $job = file_get_contents(base_path('app/Jobs/SendMediaJob.php'));

        $this->assertStringContainsString('private function cleanupTempFiles', $job);
        $this->assertGreaterThanOrEqual(
            5,
            substr_count($job, '$this->cleanupTempFiles('),
            'كل مسار انصراف يجب أن ينظّف: تخزين غير مدعوم، منشأة غائبة، جهة اتصال غائبة، نافذة مغلقة، ونجاح'
        );

        $this->assertMatchesRegularExpression(
            '/public function failed\(Throwable \$exception\): void\s*\{\s*\$this->cleanupTempFiles/',
            $job,
            'بعد استنفاد المحاولات لم يعد للملف مستهلك'
        );
    }

    /** الرمي يبقى: هو ما يُظهر الفشل ويُطلق إعادة المحاولة. */
    public function test_a_failed_send_still_throws_outside_a_batch(): void
    {
        $job = file_get_contents(base_path('app/Jobs/SendMediaJob.php'));

        $this->assertMatchesRegularExpression(
            '/throw new \\\\RuntimeException\(\s*\'WhatsApp media send failed/',
            $job
        );
    }

    // ------------------------------------------------ نسخة الترجمات

    public function test_the_locale_version_changes_with_the_file(): void
    {
        $version = LocaleVersion::current('ar');

        $this->assertNotSame('', $version);
        $this->assertSame((string) filemtime(base_path('lang/ar.json')), $version);
    }

    public function test_a_missing_locale_file_does_not_break_the_page(): void
    {
        $this->assertSame('0', LocaleVersion::current('zz-does-not-exist'));
    }

    /** البصمة تصل مع كل صفحة، بلا طلب إضافي. */
    public function test_the_version_is_rendered_in_the_page_head(): void
    {
        $view = file_get_contents(base_path('resources/views/app.blade.php'));

        $this->assertStringContainsString('name="i18n-version"', $view);
        $this->assertStringContainsString('LocaleVersion::current()', $view);
    }

    /** والواجهة تُسقط كاشها عند اختلافها. */
    public function test_the_client_drops_its_cache_when_the_version_differs(): void
    {
        $app = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString('function currentI18nVersion', $app);
        $this->assertSame(
            2,
            substr_count($app, "if ((version || '') !== currentI18nVersion()) return null;"),
            'كلا الكاشين — الإقلاع والترجمات — يجب أن يفحصا النسخة'
        );
        $this->assertSame(
            2,
            substr_count($app, 'version: currentI18nVersion(),'),
            'وكلاهما يخزّن النسخة التي بُني عليها'
        );
    }

    /** المفتاح الذي أظهر العطل موجود ومترجَم. */
    public function test_the_album_counter_key_is_translated(): void
    {
        $translations = json_decode(file_get_contents(base_path('lang/ar.json')), true);

        $this->assertArrayHasKey('{count} files', $translations);
        $this->assertStringContainsString('{count}', $translations['{count} files'], 'الترجمة يجب أن تُبقي المتغيّر');
        $this->assertNotSame('{count} files', $translations['{count} files']);
    }
}
