<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * مسار إعداد البثّ لتطبيق الجوال.
 *
 * الداشبورد يستقبل الإعداد مع كل تحميل صفحة فيتبع التبديل تلقائياً. أمّا
 * التطبيق فمفاتيحه كانت مضمّنة فيه لحظة بنائه: لو حُوّل الخادم إلى Reverb ظلّ
 * يستمع إلى Pusher — الرسائل تُحفظ ولا تصل لحظياً، فيبدو التطبيق بطيئاً لا
 * معطّلاً. بهذا المسار يسأل عند كل فتح فيتبع كل تبديل لاحق بلا إصدار جديد.
 */
class BroadcastConfigEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/broadcast-config';

    protected function setUp(): void
    {
        parent::setUp();
        BroadcastProvider::forget();

        Addon::create([
            'uuid' => (string) Str::uuid(),
            'category' => 'mobile',
            'name' => 'Mobile App',
            'logo' => 'mobile.png',
            'status' => 1,
            'is_active' => 1,
        ]);
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

    /**
     * مستخدم تطبيق مكتمل: عضوية فعّالة في منظّمة ومنظّمة مختارة. المسار يقع
     * خلف نفس حرّاس بقيّة مسارات التطبيق، فاختباره بمستخدم ناقص يقيس الحرّاس
     * لا المسار.
     */
    private function asApp(): User
    {
        $owner = User::factory()->create(['role' => 'user']);
        $organization = Organization::factory()->create(['created_by' => $owner->id]);

        $user = User::factory()->create([
            'role' => 'user',
            'current_mobile_organization_id' => $organization->id,
        ]);

        Team::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'manager',
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    // ------------------------------------------------ الحماية

    /** الإعداد يكشف مفتاح الاتصال، فلا يُعطى لمن لم يسجّل دخوله. */
    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    /**
     * السرّ يوقّع الأحداث من الخادم؛ وصوله جهازاً يعني أن حامله يبثّ باسمنا.
     */
    public function test_the_secret_never_leaves_the_server(): void
    {
        $this->useReverb();
        $this->asApp();

        $body = $this->getJson(self::ENDPOINT)->getContent();

        $this->assertStringNotContainsString('must-not-leak', $body);
        $this->assertStringNotContainsString('secret', $body);
    }

    // ------------------------------------------------- الشكل

    public function test_the_response_carries_what_the_app_needs(): void
    {
        $this->useReverb();
        $this->asApp();

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonStructure([
                'statusCode', 'success',
                'data' => ['provider', 'key', 'host', 'port', 'scheme', 'force_tls', 'cluster', 'auth_endpoint'],
            ])
            ->assertJsonPath('data.provider', 'reverb')
            ->assertJsonPath('data.key', 'zsgyjtc10xgndtlt5mdj')
            ->assertJsonPath('data.host', 'reverb.mnjz.net')
            ->assertJsonPath('data.port', 443)
            ->assertJsonPath('data.force_tls', true);
    }

    /** مسار المصادقة على القنوات يُمرَّر كاملاً — التطبيق لا يُركّبه بنفسه. */
    public function test_the_channel_auth_endpoint_is_included(): void
    {
        $this->asApp();

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.auth_endpoint', url('/api/v1/broadcasting/auth'));
    }

    /**
     * Pusher السحابي يشتقّ عنوانه من التجميعة؛ عنوان مُخترَع يكسر الاتصال،
     * فـnull هنا تعني «استعمل سلوكك الافتراضي».
     */
    public function test_the_cloud_provider_reports_no_host(): void
    {
        $this->setting('pusher_app_cluster', 'us2');
        $this->asApp();

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.provider', 'pusher')
            ->assertJsonPath('data.host', null)
            ->assertJsonPath('data.cluster', 'us2');
    }

    // ------------------------------------------------ التبديل

    /**
     * جوهر المسار: التطبيق يسأل فيتبع — وهذا ما يجعل التبديل كاملاً لا نصفياً.
     */
    public function test_the_app_follows_the_switch_without_a_new_release(): void
    {
        $this->setting('pusher_app_key', 'cloud-key');
        $this->asApp();

        $this->getJson(self::ENDPOINT)
            ->assertJsonPath('data.provider', 'pusher')
            ->assertJsonPath('data.host', null);

        $this->useReverb();

        $this->getJson(self::ENDPOINT)
            ->assertJsonPath('data.provider', 'reverb')
            ->assertJsonPath('data.host', 'reverb.mnjz.net');

        $this->setting(BroadcastProvider::SETTING_KEY, BroadcastProvider::PUSHER);

        $this->getJson(self::ENDPOINT)
            ->assertJsonPath('data.provider', 'pusher')
            ->assertJsonPath('data.key', 'cloud-key');
    }

    /** الردّ لا يُخزّن مؤقتاً: التبديل يجب أن يظهر عند أول سؤال بعده. */
    public function test_two_requests_reflect_a_switch_between_them(): void
    {
        $this->asApp();
        $this->getJson(self::ENDPOINT)->assertJsonPath('data.provider', 'pusher');

        $this->useReverb();

        $this->getJson(self::ENDPOINT)->assertJsonPath('data.provider', 'reverb');
    }

    // ------------------------------------------------ الحراسة

    /** المسار مسجّل تحت بادئة التطبيق وخلف المصادقة. */
    public function test_the_route_is_registered_for_the_app(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->map(fn ($r) => $r->methods()[0] . ' ' . $r->uri());

        $this->assertTrue($routes->contains('GET api/v1/broadcast-config'));
    }
}
