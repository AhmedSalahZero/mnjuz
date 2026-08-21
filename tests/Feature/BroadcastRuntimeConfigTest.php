<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Providers\BroadcastConfigServiceProvider;
use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * وصول الإعداد إلى وقت التشغيل — لا مجرّد صحّته في الجدول.
 *
 * عطلٌ وقع فعلاً: بُدّل المزوّد إلى Reverb، فبقيت رسائل العملاء الواردة لا تصل
 * إلى الداشبورد لحظياً. الجدول كان صحيحاً والخادم يعمل والمفاتيح سليمة —
 * والإعداد لم يبلغ العامل أصلاً. سببان اجتمعا:
 *
 *   ١. apply() كانت داخل بوّابة ENABLE_DATABASE_CONFIG وهي مغلقة، فلم تُطبَّق
 *      قطّ عند الإقلاع. والمواضع الثلاثة التي تستدعيها لا يعمل أيٌّ منها داخل
 *      وظيفة البثّ المُطابَرة — فبثّ العامل إلى ما في .env: سحابة Pusher.
 *
 *   ٢. مدير البثّ يبني عميل Pusher عند أوّل استعمال ويحتفظ به، فضبط الإعداد
 *      بعد ذلك بلا أثر. لا يظهر في php-fpm لأن كل طلب عملية جديدة، ويظهر في
 *      عامل الطابور — عملية تعيش ساعات.
 *
 * كلا العطلين صامت: الرسائل تُحفظ ولا تصل لحظياً، فيبدو النظام بطيئاً لا
 * معطّلاً. لذلك تُحرَس هنا بالإعداد الفعلي وقت التشغيل لا بقيمة الجدول.
 */
class BroadcastRuntimeConfigTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 'broadcasting.connections.pusher';

    protected function setUp(): void
    {
        parent::setUp();
        BroadcastProvider::forget();
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        BroadcastProvider::forget();
    }

    private function useReverb(): void
    {
        $this->set(BroadcastProvider::SETTING_KEY, BroadcastProvider::REVERB);
        $this->set('reverb_app_key', 'reverb-key');
        $this->set('reverb_app_secret', 'reverb-secret');
        $this->set('reverb_app_id', '221796');
        $this->set('reverb_host', 'reverb.mnjz.net');
    }

    private function usePusher(): void
    {
        $this->set(BroadcastProvider::SETTING_KEY, BroadcastProvider::PUSHER);
        $this->set('pusher_app_key', 'cloud-key');
        $this->set('pusher_app_secret', 'cloud-secret');
        $this->set('pusher_app_id', '2089431');
        $this->set('pusher_app_cluster', 'us2');
    }

    private function runtimeHost(): ?string
    {
        return Config::get(self::SLOT . '.options.host');
    }

    /** إقلاع نظيف كما يحدث في عامل الطابور. */
    private function boot(): void
    {
        (new BroadcastConfigServiceProvider($this->app))->boot();
    }

    // ------------------------------------------------- العطل الأوّل

    /**
     * جوهر العطل: البوّابة مغلقة والإعداد يجب أن يصل رغم ذلك.
     */
    public function test_the_connection_is_applied_at_boot_even_with_the_database_config_gate_closed(): void
    {
        $this->assertFalse((bool) env('ENABLE_DATABASE_CONFIG', false), 'البوّابة مفتوحة — الاختبار لا يقيس الحالة المقصودة.');

        $this->useReverb();
        Config::set(self::SLOT, ['driver' => 'pusher', 'options' => ['host' => 'api-us2.pusher.com']]);

        $this->boot();

        $this->assertSame('reverb.mnjz.net', $this->runtimeHost());
    }

    /** والعكس: بيئة على Pusher لا تُدفع إلى Reverb بمجرّد وجود صفوفه. */
    public function test_a_pusher_environment_stays_on_pusher(): void
    {
        $this->usePusher();
        $this->set('reverb_host', 'reverb.mnjz.net');

        $this->boot();

        $this->assertSame('api-us2.pusher.com', $this->runtimeHost());
        $this->assertSame('cloud-key', Config::get(self::SLOT . '.key'));
    }

    /** الإعداد يصل كاملاً — لا المفاتيح وحدها كما كانت المواضع الثلاثة تفعل. */
    public function test_the_applied_connection_carries_host_port_and_scheme(): void
    {
        $this->useReverb();
        $this->set('reverb_port', '443');
        $this->set('reverb_scheme', 'https');

        $this->boot();

        $this->assertSame('reverb.mnjz.net', Config::get(self::SLOT . '.options.host'));
        $this->assertSame(443, Config::get(self::SLOT . '.options.port'));
        $this->assertSame('https', Config::get(self::SLOT . '.options.scheme'));
        $this->assertTrue(Config::get(self::SLOT . '.options.useTLS'));
    }

    // ------------------------------------------------- العطل الثاني

    /** عنوان عميل Pusher داخل مُذيع محفوظ. */
    private function clientHost(PusherBroadcaster $driver): ?string
    {
        $pusher = $driver->getPusher();
        $settings = new \ReflectionProperty($pusher, 'settings');
        $settings->setAccessible(true);

        return $settings->getValue($pusher)['host'] ?? null;
    }

    /**
     * المُذيع المحفوظ يتبع الوجهة الجديدة، وإلّا ظلّ العامل يبثّ إلى القديمة
     * إلى أن يُعاد تشغيله.
     */
    public function test_a_cached_broadcaster_follows_the_new_destination(): void
    {
        $this->usePusher();
        BroadcastProvider::apply();

        $driver = Broadcast::connection('pusher');
        $this->assertSame('api-us2.pusher.com', $this->clientHost($driver));

        $this->useReverb();
        BroadcastProvider::apply();

        $this->assertSame('reverb.mnjz.net', $this->clientHost(Broadcast::connection('pusher')));
    }

    /**
     * الانحدار الذي أوقع 403: Broadcast::channel تُسجّل التفويض على كائن
     * المُذيع، و BroadcastServiceProvider يفعل ذلك قبل هذا المزوّد. فإسقاط
     * المُذيع عند التبديل كان يمحو كل القنوات — فلا تُطابق قناة، ويردّ
     * /broadcasting/auth بـ403، ويفشل اشتراك المتصفّح بصمت.
     */
    public function test_switching_keeps_the_registered_channel_authorizations(): void
    {
        $this->usePusher();
        BroadcastProvider::apply();

        $driver = Broadcast::connection('pusher');
        $driver->channel('probe.{id}', fn () => true);

        $this->useReverb();
        BroadcastProvider::apply();

        $channels = new \ReflectionProperty(Broadcast::connection('pusher'), 'channels');
        $channels->setAccessible(true);

        $this->assertArrayHasKey(
            'probe.{id}',
            $channels->getValue(Broadcast::connection('pusher')),
            'ضاع تفويض القناة عند التبديل — كل مصادقة ستردّ 403.'
        );
    }

    /** والمُذيع نفسه يبقى كائناً واحداً: ما سُجّل عليه يبقى مسجّلاً. */
    public function test_the_broadcaster_instance_survives_a_switch(): void
    {
        $this->usePusher();
        BroadcastProvider::apply();

        $before = Broadcast::connection('pusher');

        $this->useReverb();
        BroadcastProvider::apply();

        $this->assertSame($before, Broadcast::connection('pusher'));
    }

    /**
     * وبلا تغيير لا يُلمَس شيء: apply() تُستدعى مع كل إنشاء لخدمة واتساب،
     * وإعادة بناء العميل في كل مرّة إهدارٌ لاتصال يعمل.
     */
    public function test_an_unchanged_connection_keeps_the_existing_client(): void
    {
        $this->usePusher();
        BroadcastProvider::apply();

        $before = Broadcast::connection('pusher')->getPusher();

        BroadcastProvider::forget();
        BroadcastProvider::apply();

        $this->assertSame($before, Broadcast::connection('pusher')->getPusher());
    }

    // ------------------------------------------------ عامل الطابور

    /**
     * العامل يتبع التبديل بلا إعادة تشغيل: يقرأ الإعدادات قبل كل وظيفة.
     */
    public function test_a_running_worker_follows_a_switch_between_jobs(): void
    {
        $this->usePusher();
        $this->boot();

        $this->assertSame('api-us2.pusher.com', $this->runtimeHost());

        // المشغّل يبدّل بينما العامل يعمل.
        $this->useReverb();

        // الوظيفة التالية تبدأ.
        event(new Looping('redis', 'high'));

        $this->assertSame('reverb.mnjz.net', $this->runtimeHost());
    }

    /** والرجوع كذلك — العودة عند الخلل يجب أن تكون بنفس السرعة. */
    public function test_a_running_worker_follows_a_switch_back(): void
    {
        $this->useReverb();
        $this->usePusher();
        $this->set(BroadcastProvider::SETTING_KEY, BroadcastProvider::REVERB);
        $this->boot();

        $this->assertSame('reverb.mnjz.net', $this->runtimeHost());

        $this->set(BroadcastProvider::SETTING_KEY, BroadcastProvider::PUSHER);
        event(new Looping('redis', 'high'));

        $this->assertSame('api-us2.pusher.com', $this->runtimeHost());
    }

    // ---------------------------------------------------- المتانة

    /**
     * تعذّر إسقاط العميل لا يُسقط البثّ نفسه: البثّ ميزة مساعدة ولا يصحّ أن
     * يُفشل الرسالة التي يرافقها.
     */
    public function test_apply_survives_a_broken_broadcast_manager(): void
    {
        $this->app->bind(BroadcastingFactory::class, function () {
            throw new \RuntimeException('مدير البثّ معطّل');
        });

        $this->useReverb();

        BroadcastProvider::apply();

        $this->assertSame('reverb.mnjz.net', $this->runtimeHost());
    }
}
