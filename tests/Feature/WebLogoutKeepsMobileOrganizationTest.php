<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Team;
use App\Services\UserDeviceService;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Cycle\CycleTestCase;

/**
 * خروج الداشبورد لا يمسّ منشأة التطبيق.
 *
 * حادثة 2026-11-15: دخولٌ على اللوحة من حاسب ثانٍ جدّد معرّف جهاز الويب، فطرد
 * EnsureDeviceIsCurrent جلسة الحاسب الأوّل عند نبضتها التالية — وهي طلب axios
 * (JSON). ومستمع الخروج كان يسأل detectPlatform() أيّ عمود يمسح، فيرى JSON
 * فيظنّه الجوال، فيمسح current_mobile_organization_id ويترك عمود الويب.
 *
 * والمستخدم في أكثر من منشأة لا تُختار له منشأة تلقائياً، فصار كل طلب v1 من
 * التطبيق يُرفض بـ403 «يلزم اختيار منشأة» — وأوّل ما ظهر للمستخدم «التحديثات
 * المباشرة غير متاحة» لأن /broadcast-config رُفض، لا لعيبٍ فيه.
 *
 * حدث Logout لا يطلقه إلا حارس جلسة (الداشبورد)؛ خروج التطبيق يمسح عموده بنفسه
 * في AuthController::logout. فالمستمع يمسح عمود الويب وحده مهما كان شكل الطلب.
 */
class WebLogoutKeepsMobileOrganizationTest extends CycleTestCase
{
    /** منشأة الويب الحالية؛ منشأة التطبيق هي $this->organization. */
    private Organization $webOrganization;

    protected function setUp(): void
    {
        parent::setUp();

        Addon::create([
            'uuid' => (string) Str::uuid(),
            'category' => 'mobile',
            'name' => 'Mobile App',
            'logo' => 'mobile.png',
            'status' => 1,
            'is_active' => 1,
        ]);

        // منشأتان: بمنشأة واحدة يُعيدها ensureValid تلقائياً فيختفي العطل.
        $this->webOrganization = Organization::factory()->create(['created_by' => $this->owner->id]);
        Team::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->webOrganization->id,
            'role' => 'owner',
            'created_by' => $this->owner->id,
        ]);

        $this->owner->forceFill([
            'current_mobile_organization_id' => $this->organization->id,
            'current_web_organization_id' => $this->webOrganization->id,
        ])->save();
    }

    /**
     * جلسة ويب قديمة: سُجّل الحاسب الأوّل، ثم دخل حاسب ثانٍ فجدّد المعرّف.
     *
     * @return array<string, mixed>
     */
    private function staleWebSession(): array
    {
        $devices = app(UserDeviceService::class);
        $desktop = ['device_name' => 'Desktop', 'device_type' => 'desktop', 'browser' => 'Chrome'];

        $first = $devices->registerOrTouch($this->owner, $desktop + ['platform' => 'macOS'], 'web');
        $devices->registerOrTouch($this->owner, $desktop + ['platform' => 'Windows'], 'web');

        return [
            'device_identifier' => $first->device_identifier,
            'current_organization' => $this->webOrganization->id,
        ];
    }

    private function assertMobileOrganizationKept(): void
    {
        $this->assertSame(
            (int) $this->organization->id,
            (int) $this->owner->fresh()->current_mobile_organization_id,
            'خروج الداشبورد مسح منشأة التطبيق.'
        );
    }

    /** الحادثة نفسها: الطرد يقع على نبضة axios. */
    public function test_evicting_a_stale_dashboard_on_its_json_heartbeat_keeps_the_app_organization(): void
    {
        $this->actingAs($this->owner, 'user')
            ->withSession($this->staleWebSession())
            ->postJson('/performance/heartbeat', ['visible' => true])
            ->assertRedirect(route('login'));

        $this->assertMobileOrganizationKept();
        $this->assertNull($this->owner->fresh()->current_web_organization_id, 'عمود الويب يُمسح كأي خروج من اللوحة.');
    }

    /** السلوك القديم لطلب صفحة عادي باقٍ كما هو. */
    public function test_evicting_a_stale_dashboard_on_a_page_request_keeps_the_app_organization(): void
    {
        $this->actingAs($this->owner, 'user')
            ->withSession($this->staleWebSession())
            ->get('/dashboard')
            ->assertRedirect(route('login'));

        $this->assertMobileOrganizationKept();
        $this->assertNull($this->owner->fresh()->current_web_organization_id);
    }

    /**
     * أيّ خروج من اللوحة أثناء طلب XHR (تغيير كلمة المرور، إخراج جهاز...) يمرّ
     * بالمستمع نفسه، لا الطرد وحده.
     */
    public function test_any_dashboard_logout_during_an_xhr_request_clears_only_the_web_column(): void
    {
        $this->app->instance('request', Request::create('/settings', 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]));

        event(new Logout('user', $this->owner));

        $this->assertMobileOrganizationKept();
        $this->assertNull($this->owner->fresh()->current_web_organization_id);
    }

    /** ما رآه المستخدم: بعد الطرد يبقى إعداد البثّ متاحاً للتطبيق. */
    public function test_the_app_still_gets_its_broadcast_config_after_the_dashboard_is_evicted(): void
    {
        $this->actingAs($this->owner, 'user')
            ->withSession($this->staleWebSession())
            ->postJson('/performance/heartbeat', ['visible' => true])
            ->assertRedirect(route('login'));

        Sanctum::actingAs($this->owner->fresh());

        $this->getJson('/api/v1/broadcast-config')
            ->assertOk()
            ->assertJsonPath('data.channel', 'presence-chats.ch' . $this->organization->id . '.' . $this->owner->id);
    }

    /** الاتجاه الآخر: خروج التطبيق ما زال يمسح عموده ورموزه، ولا يمسّ الويب. */
    public function test_the_app_logout_still_clears_only_the_mobile_column_and_tokens(): void
    {
        $token = $this->owner->createToken('iPhone')->plainTextToken;

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $this->owner->fresh();
        $this->assertNull($fresh->current_mobile_organization_id);
        $this->assertSame((int) $this->webOrganization->id, (int) $fresh->current_web_organization_id);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $this->owner->id]);
    }
}
