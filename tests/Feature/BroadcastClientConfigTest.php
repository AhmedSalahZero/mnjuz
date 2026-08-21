<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إعداد البثّ الذاهب إلى المتصفّح.
 *
 * كانت الواجهة تستقبل المفتاح والتجميعة فقط، وتبني اتصالها منهما. وReverb لا
 * يعرف التجميعات ويحتاج عنواناً صريحاً — فكان تبديل المزوّد يُحوّل الخادم
 * وحده ويترك المتصفّح على السحابة: رسالة تُبثّ إلى خادمنا ولا يسمعها أحد.
 *
 * ثلاثة مواضع تُغذّي الواجهة (المحادثات، الفوترة، الإعداد المشترك) وثلاث شاشات
 * تبني الاتصال. هذه الاختبارات تحرس أن الجميع يمرّ بمصدر واحد.
 */
class BroadcastClientConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BroadcastProvider::forget();
    }

    private function setting(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        BroadcastProvider::forget();
    }

    private function useReverb(): void
    {
        $this->setting(BroadcastProvider::SETTING_KEY, BroadcastProvider::REVERB);
        $this->setting('reverb_app_key', 'zsgyjtc10xgndtlt5mdj');
        $this->setting('reverb_app_secret', 'must-not-leak');
        $this->setting('reverb_host', 'reverb.mnjz.net');
    }

    // ------------------------------------------ الشكل المُرسَل

    public function test_the_client_receives_everything_it_needs_and_nothing_more(): void
    {
        $this->useReverb();

        $config = BroadcastProvider::clientConfig();

        $this->assertSame(
            ['provider', 'key', 'cluster', 'host', 'port', 'scheme', 'force_tls'],
            array_keys($config)
        );
    }

    /** السرّ يوقّع الأحداث من الخادم — وصوله المتصفّح يعني أن أي زائر يبثّ. */
    public function test_the_secret_never_reaches_the_browser(): void
    {
        $this->useReverb();

        $this->assertStringNotContainsString(
            'must-not-leak',
            json_encode(BroadcastProvider::clientConfig())
        );
    }

    /**
     * Pusher السحابي يشتقّ عنوانه من التجميعة؛ عنوان مُخترَع يكسر الاتصال.
     */
    public function test_the_cloud_provider_sends_no_host(): void
    {
        $this->setting('pusher_app_cluster', 'us2');

        $config = BroadcastProvider::clientConfig();

        $this->assertSame('pusher', $config['provider']);
        $this->assertNull($config['host']);
        $this->assertSame('us2', $config['cluster']);
    }

    /** وReverb بلا عنوان لا يُتّصل به أصلاً. */
    public function test_our_server_sends_an_explicit_host(): void
    {
        $this->useReverb();

        $config = BroadcastProvider::clientConfig();

        $this->assertSame('reverb', $config['provider']);
        $this->assertSame('reverb.mnjz.net', $config['host']);
        $this->assertSame(443, $config['port']);
        $this->assertTrue($config['force_tls']);
    }

    /** التبديل يظهر في المتصفّح عند التحميل التالي — بلا نشر ولا إعادة بناء. */
    public function test_switching_changes_what_the_browser_gets(): void
    {
        $this->setting('pusher_app_key', 'cloud-key');
        $this->assertNull(BroadcastProvider::clientConfig()['host']);

        $this->useReverb();
        $this->assertSame('reverb.mnjz.net', BroadcastProvider::clientConfig()['host']);

        $this->setting(BroadcastProvider::SETTING_KEY, BroadcastProvider::PUSHER);
        $this->assertNull(BroadcastProvider::clientConfig()['host']);
        $this->assertSame('cloud-key', BroadcastProvider::clientConfig()['key']);
    }

    // -------------------------------- حراسة مصادر الواجهة

    /**
     * @dataProvider serverSources
     */
    public function test_every_server_source_uses_the_shared_builder(string $file): void
    {
        $source = file_get_contents(base_path($file));

        $this->assertStringContainsString('BroadcastProvider::clientConfig()', $source, $file);
        $this->assertDoesNotMatchRegularExpression(
            "/Setting::whereIn\('key', \[\s*\n\s*'pusher_app_key',/",
            $source,
            "{$file} ما زال يقرأ مفاتيح Pusher مباشرةً"
        );
    }

    public static function serverSources(): array
    {
        return [
            'صفحة المحادثات' => ['app/Services/ChatService.php'],
            'صفحة الفوترة' => ['app/Http/Controllers/User/BillingController.php'],
            'الإعداد المشترك' => ['app/Http/Middleware/HandleInertiaRequests.php'],
        ];
    }

    /**
     * بناء Echo في صفحة بمفتاح وتجميعة يُبقيها على السحابة بعد تبديل الباقي —
     * وهو انحدار صامت: الشاشة تبدو سليمة ولا تصلها الأحداث.
     */
    public function test_no_screen_builds_its_own_connection(): void
    {
        $offenders = [];
        $root = base_path('resources/js');

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!in_array($file->getExtension(), ['vue', 'js'], true)) {
                continue;
            }
            if (str_ends_with($file->getPathname(), 'resources/js/echo.js')) {
                continue; // البنّاء المشترك هو الموضع الوحيد المسموح
            }

            // الكود الحيّ دون المعلَّق: bootstrap.js يحوي مثالاً معلّقاً من
            // هيكل Laravel، وعدّه بناءً حيّاً إنذار كاذب.
            $source = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents($file->getPathname()));

            if (preg_match('/new\s+Echo\s*\(/', $source)) {
                $offenders[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'يجب أن تمرّ كلّها بـgetEchoInstance()');
    }

    /** وأن السلوك نفسه مُغطّى باختبار يشغّل الملف المشحون. */
    public function test_the_shipped_builder_is_covered_by_a_behavioural_test(): void
    {
        exec('node --version 2>/dev/null', $out, $status);
        if ($status !== 0) {
            $this->markTestSkipped('node غير متاح');
        }

        $script = base_path('tests/js/echo-broadcast-options.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
    }
}
