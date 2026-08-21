<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * مزوّد البثّ الفعّال ومصدر إعداده الوحيد.
 *
 * ثلاثة مواضع كانت تكتب إعداد البثّ كاملاً وقت التشغيل بالمفاتيح وحدها، فتمسح
 * host و port و scheme ويعود البثّ إلى سحابة Pusher مهما ضُبط في .env. أي
 * تبديل للوجهة كان سيبدو ناجحاً على الصفحات العادية ويفشل صامتاً في المحادثات
 * والويب هوك — وهو أسوأ شكل للعطل.
 *
 * هذه الاختبارات تحرس أمرين: أن الإعداد يبقى كاملاً بعد أي إعادة كتابة، وأن
 * نشر هذا التغيير وحده لا يُبدّل شيئاً — النظام يبقى على Pusher حتى يُطلب غيره.
 */
class BroadcastProviderTest extends TestCase
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
        $this->setting('reverb_app_secret', 'test-secret');
        $this->setting('reverb_app_id', '221796');
        $this->setting('reverb_host', 'reverb.mnjz.net');
    }

    // ------------------------------------------- الافتراضي لا يتغيّر

    /**
     * أهمّ اختبار في الملف: نشر هذا العمل وحده يجب ألّا يُبدّل الوجهة. أي بيئة
     * لم تُضبط بعد تبقى على Pusher كما كانت.
     */
    public function test_nothing_switches_until_it_is_asked_for(): void
    {
        $this->assertSame(BroadcastProvider::PUSHER, BroadcastProvider::active());
    }

    /** @dataProvider unrecognisedValues */
    public function test_an_unrecognised_provider_falls_back_to_pusher(string $value): void
    {
        $this->setting(BroadcastProvider::SETTING_KEY, $value);

        $this->assertSame(BroadcastProvider::PUSHER, BroadcastProvider::active());
    }

    public static function unrecognisedValues(): array
    {
        return [
            'فارغ' => [''],
            'مسافات' => ['   '],
            'اسم مجهول' => ['ably'],
            'خطأ إملائي' => ['reverbb'],
        ];
    }

    public function test_the_provider_name_is_case_insensitive(): void
    {
        $this->useReverb();
        $this->setting(BroadcastProvider::SETTING_KEY, 'REVERB');

        $this->assertSame(BroadcastProvider::REVERB, BroadcastProvider::active());
    }

    // --------------------------------- الإعداد يبقى كاملاً

    /**
     * جوهر العطل الأصلي: العنوان والمنفذ والمخطّط كانت تُمسح عند كل إعادة كتابة.
     *
     * @dataProvider bothProviders
     */
    public function test_the_connection_always_carries_host_port_and_scheme(string $provider): void
    {
        if ($provider === BroadcastProvider::REVERB) {
            $this->useReverb();
        }

        $options = BroadcastProvider::connection()['options'];

        foreach (['host', 'port', 'scheme', 'useTLS', 'cluster'] as $key) {
            $this->assertArrayHasKey($key, $options, "المزوّد {$provider} فقد {$key}");
        }

        $this->assertNotSame('', trim((string) $options['host']), "المزوّد {$provider} بلا عنوان");
    }

    public static function bothProviders(): array
    {
        return [
            'pusher' => [BroadcastProvider::PUSHER],
            'reverb' => [BroadcastProvider::REVERB],
        ];
    }

    /** التطبيق على وقت التشغيل لا يُنقص الإعداد — وهو ما كان يقع. */
    public function test_applying_the_config_does_not_strip_the_host(): void
    {
        $this->useReverb();

        BroadcastProvider::apply();

        $options = Config::get('broadcasting.connections.pusher.options');
        $this->assertSame('reverb.mnjz.net', $options['host']);
        $this->assertSame(443, (int) $options['port']);
        $this->assertTrue($options['useTLS']);
    }

    /** ولو طُبّق مرّتين — المواضع الثلاثة قد تتعاقب في طلب واحد. */
    public function test_applying_twice_is_stable(): void
    {
        $this->useReverb();

        BroadcastProvider::apply();
        $first = Config::get('broadcasting.connections.pusher');
        BroadcastProvider::apply();

        $this->assertSame($first, Config::get('broadcasting.connections.pusher'));
    }

    // ------------------------------------------------ التبديل

    /** التبديل قيمة واحدة، والعودة كذلك — بلا إدخال مفاتيح من جديد. */
    public function test_switching_back_and_forth_needs_only_one_value(): void
    {
        $this->setting('pusher_app_key', 'pusher-key');
        $this->useReverb();

        $this->assertSame('zsgyjtc10xgndtlt5mdj', BroadcastProvider::connection()['key']);

        $this->setting(BroadcastProvider::SETTING_KEY, BroadcastProvider::PUSHER);
        $this->assertSame('pusher-key', BroadcastProvider::connection()['key']);

        $this->setting(BroadcastProvider::SETTING_KEY, BroadcastProvider::REVERB);
        $this->assertSame('zsgyjtc10xgndtlt5mdj', BroadcastProvider::connection()['key']);
    }

    /** بيانات المزوّد الآخر تبقى محفوظة — عليها يقوم التبديل الفوري. */
    public function test_the_other_providers_credentials_survive_a_switch(): void
    {
        $this->setting('pusher_app_key', 'pusher-key');
        $this->useReverb();

        $this->assertSame('pusher-key', Setting::where('key', 'pusher_app_key')->value('value'));
        $this->assertSame('zsgyjtc10xgndtlt5mdj', Setting::where('key', 'reverb_app_key')->value('value'));
    }

    /** كلا المزوّدين يستعمل سائق pusher: Reverb يتكلّم بروتوكوله. */
    public function test_both_providers_use_the_pusher_driver(): void
    {
        $this->assertSame('pusher', BroadcastProvider::connection()['driver']);

        $this->useReverb();
        $this->assertSame('pusher', BroadcastProvider::connection()['driver']);
    }

    // -------------------------------------- ما يصل الواجهة

    public function test_the_client_config_never_leaks_the_secret(): void
    {
        $this->useReverb();

        $client = BroadcastProvider::clientConfig();

        $this->assertArrayNotHasKey('secret', $client);
        $this->assertStringNotContainsString('test-secret', json_encode($client));
    }

    public function test_the_client_config_carries_what_the_client_needs(): void
    {
        $this->useReverb();

        $client = BroadcastProvider::clientConfig();

        $this->assertSame('reverb', $client['provider']);
        $this->assertSame('zsgyjtc10xgndtlt5mdj', $client['key']);
        $this->assertSame('reverb.mnjz.net', $client['host']);
        $this->assertSame(443, $client['port']);
        $this->assertTrue($client['force_tls']);
    }

    /**
     * Pusher السحابي يشتقّ عنوانه من التجميعة، فإرسال عنوان مُخترَع للعميل
     * كان سيكسر الاتصال بدل أن يُصلحه.
     */
    public function test_the_cloud_provider_sends_no_host_to_the_client(): void
    {
        $this->setting('pusher_app_cluster', 'us2');

        $client = BroadcastProvider::clientConfig();

        $this->assertSame('pusher', $client['provider']);
        $this->assertNull($client['host']);
        $this->assertSame('us2', $client['cluster']);
    }

    // ----------------------------------------- الصمود

    /** جدول الإعدادات يسبق .env: اللوحة هي ما يعدّله المشغّل. */
    public function test_settings_take_precedence_over_the_environment(): void
    {
        $this->setting('pusher_app_key', 'from-settings');

        $this->assertSame('from-settings', BroadcastProvider::connection()['key']);
    }

    /** صفّ فارغ لا يمحو قيمة البيئة — وإلا عطّل حقلٌ لم يُملأ البثّ كلّه. */
    public function test_an_empty_setting_falls_back_to_the_environment(): void
    {
        config(['app.env' => 'testing']);
        $this->setting('pusher_app_key', '');

        $this->assertSame((string) env('PUSHER_APP_KEY'), (string) BroadcastProvider::connection()['key']);
    }

    // ------------------------------- حراسة المواضع الثلاثة

    /**
     * أيّ موضع يعود لكتابة الإعداد بنفسه يُعيد العطل: يمسح العنوان فيرتدّ البثّ
     * إلى السحابة، ولا يظهر ذلك إلا في المحادثات والويب هوك.
     */
    public function test_no_file_writes_the_broadcast_config_by_itself(): void
    {
        $offenders = [];

        foreach (['app', 'modules'] as $dir) {
            $path = base_path($dir);
            if (!is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                if (str_contains($source, "Config::set('broadcasting.connections")) {
                    $offenders[] = str_replace(base_path() . '/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'يجب أن تمرّ كلّها بـBroadcastProvider::apply()');
    }

    /** @dataProvider siteThatUsedToOverwrite */
    public function test_each_former_offender_now_uses_the_shared_source(string $file): void
    {
        $source = file_get_contents(base_path($file));

        $this->assertStringContainsString('BroadcastProvider::apply()', $source, $file);
    }

    public static function siteThatUsedToOverwrite(): array
    {
        return [
            'خدمة واتساب' => ['app/Services/WhatsappService.php'],
            'متحكّم الويب هوك' => ['app/Http/Controllers/WebhookController.php'],
            'خدمة باي بال' => ['app/Services/PayPalService.php'],
            'مزوّد الإعداد' => ['app/Providers/BroadcastConfigServiceProvider.php'],
        ];
    }
}
