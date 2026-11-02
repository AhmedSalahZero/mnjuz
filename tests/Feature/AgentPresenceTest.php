<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Services\AgentPerformanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Cycle\CycleTestCase;

/**
 * حالة «متصل» في صفحة الأداء: فورية عند الدخول والتبديل، وتتجدّد وحدها.
 *
 * العطل: من يسجّل دخوله أو يبدّل شركته يبقى «غير متصل» وقتاً طويلاً. السببان:
 * الحضور كان يُثبَت من نبضة المتصفّح وحدها — وهي تنطلق بعد أن تنتهي صفحة الشركة
 * الجديدة من التحميل — والصفحة نفسها تحسب الحالة مرة عند التحميل ثم لا تتجدّد.
 *
 * فالخادم الآن يثبّت الحضور عند الدخول والتبديل وعند فتح الصفحة، وتسأل الصفحة
 * نقطة خفيفة دورياً.
 */
class AgentPresenceTest extends CycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->planWithPerformance($this->organization, true);
    }

    // ------------------------------------------------- التجهيز

    /** الخطة التي أُنشئت للمنظمة، مع ميزة الأداء مفعّلة أو لا. */
    private function planWithPerformance(Organization $organization, bool $enabled): void
    {
        $subscription = Subscription::where('organization_id', $organization->id)->first();

        if (!$subscription) {
            $plan = SubscriptionPlan::create(['name' => 'Plan ' . Str::random(4), 'price' => 0, 'period' => 'monthly', 'metadata' => '{}']);
            $subscription = Subscription::create([
                'uuid' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'valid_until' => now()->addYear(),
            ]);
        }

        $subscription->plan->update([
            'metadata' => json_encode(['message_limit' => -1] + ($enabled ? ['agent_performance' => true] : [])),
        ]);
    }

    private function otherOrganization(bool $withFeature = true, bool $ownerIsMember = true): Organization
    {
        $organization = Organization::factory()->create(['created_by' => $this->owner->id]);

        // خطة منفصلة لكل منظمة: تعديل ميزة إحداهما لا يمسّ الأخرى.
        $plan = SubscriptionPlan::create([
            'name' => 'Other ' . Str::random(4),
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => -1] + ($withFeature ? ['agent_performance' => true] : [])),
        ]);
        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        if ($ownerIsMember) {
            Team::factory()->create([
                'user_id' => $this->owner->id,
                'organization_id' => $organization->id,
                'role' => 'owner',
                'created_by' => $this->owner->id,
            ]);
        }

        return $organization;
    }

    private function member(string $role = 'agent'): User
    {
        $user = User::factory()->create(['role' => 'user']);
        Team::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $this->organization->id,
            'role' => $role,
            'created_by' => $this->owner->id,
        ]);

        return $user;
    }

    private function asOwner(): void
    {
        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);
    }

    /** نبضة قديمة بالقدر المطلوب. */
    private function pingedAt(Organization $organization, User $user, \DateTimeInterface $at, int $activeSeconds = 0): void
    {
        DB::table('agent_activity')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'activity_date' => $at->format('Y-m-d'),
            'active_seconds' => $activeSeconds,
            'last_ping_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function activity(Organization $organization, User $user): ?object
    {
        return DB::table('agent_activity')
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->orderByDesc('activity_date')
            ->first();
    }

    private function presenceOf(User $user, ?Organization $organization = null): array
    {
        $rows = (new AgentPerformanceService(($organization ?? $this->organization)->id))->presence();

        return collect($rows)->firstWhere('user_id', $user->id) ?? [];
    }

    // ------------------------------------------------- markPresent

    public function test_marking_present_records_the_user_now(): void
    {
        AgentPerformanceService::markPresent($this->organization->id, $this->owner->id);

        $row = $this->activity($this->organization, $this->owner);
        $this->assertNotNull($row, 'لم يُسجَّل حضور.');
        $this->assertEqualsWithDelta(now()->timestamp, strtotime($row->last_ping_at), 5);
        $this->assertSame(0, (int) $row->active_seconds, 'الحضور لا يُحتسب وقتاً نشطاً.');
        $this->assertTrue($this->presenceOf($this->owner)['online']);
    }

    /** الحضور غير النشاط: ينعش «آخر ظهور» ولا يزيد الوقت النشط. */
    public function test_marking_present_does_not_add_active_time(): void
    {
        $this->pingedAt($this->organization, $this->owner, now()->subSeconds(100), 10);

        AgentPerformanceService::markPresent($this->organization->id, $this->owner->id);

        $row = $this->activity($this->organization, $this->owner);
        $this->assertSame(10, (int) $row->active_seconds, 'زاد الوقت النشط بمجرّد إثبات الحضور.');
        $this->assertEqualsWithDelta(now()->timestamp, strtotime($row->last_ping_at), 5, 'لم تُنعَش آخر نبضة.');
    }

    public function test_marking_present_twice_keeps_a_single_row_per_day(): void
    {
        AgentPerformanceService::markPresent($this->organization->id, $this->owner->id);
        AgentPerformanceService::markPresent($this->organization->id, $this->owner->id);

        $this->assertSame(1, DB::table('agent_activity')->where('user_id', $this->owner->id)->count());
    }

    public function test_nothing_is_recorded_when_the_plan_lacks_the_feature(): void
    {
        $this->planWithPerformance($this->organization, false);

        AgentPerformanceService::markPresent($this->organization->id, $this->owner->id);

        $this->assertNull($this->activity($this->organization, $this->owner));
    }

    /** من لم يعد عضواً لا يُكتب له نشاط في منظمة — معرّف «آخر منظمة» قد يكون قديماً. */
    public function test_a_non_member_is_not_recorded(): void
    {
        $outsider = User::factory()->create(['role' => 'user']);

        AgentPerformanceService::markPresent($this->organization->id, $outsider->id);

        $this->assertNull($this->activity($this->organization, $outsider));
    }

    public function test_a_removed_member_is_not_recorded(): void
    {
        $agent = $this->member();
        Team::where('user_id', $agent->id)->delete();

        AgentPerformanceService::markPresent($this->organization->id, $agent->id);

        $this->assertNull($this->activity($this->organization, $agent));
    }

    public function test_invalid_ids_are_ignored_without_failing(): void
    {
        AgentPerformanceService::markPresent(0, $this->owner->id);
        AgentPerformanceService::markPresent($this->organization->id, 0);
        AgentPerformanceService::markPresent(-1, -1);

        $this->assertSame(0, DB::table('agent_activity')->count());
    }

    // ------------------------------------------------- presence()

    public function test_a_recent_ping_is_online_and_an_old_one_is_offline(): void
    {
        $fresh = $this->member();
        $stale = $this->member();
        $this->pingedAt($this->organization, $fresh, now()->subMinutes(4));
        $this->pingedAt($this->organization, $stale, now()->subMinutes(6));

        $this->assertTrue($this->presenceOf($fresh)['online'], 'نبضة قبل 4 دقائق داخل النافذة.');
        $this->assertFalse($this->presenceOf($stale)['online'], 'نبضة قبل 6 دقائق خارج النافذة.');
    }

    public function test_a_user_who_never_pinged_is_offline(): void
    {
        $agent = $this->member();

        $row = $this->presenceOf($agent);

        $this->assertFalse($row['online']);
        $this->assertNull($row['last_activity_at']);
    }

    /** من يرسل رسائل حاضرٌ ولو كان تبويبه في الخلفية (لا نبضة). */
    public function test_a_recent_outbound_message_counts_as_presence(): void
    {
        $agent = $this->member();
        $contact = $this->contact();
        $this->chat($contact, ['type' => 'outbound', 'user_id' => $agent->id, 'created_at' => now()->subMinute()]);

        $this->assertTrue($this->presenceOf($agent)['online']);
    }

    public function test_an_old_outbound_message_does_not(): void
    {
        $agent = $this->member();
        $contact = $this->contact();
        $this->chat($contact, ['type' => 'outbound', 'user_id' => $agent->id, 'created_at' => now()->subMinutes(20)]);

        $this->assertFalse($this->presenceOf($agent)['online']);
    }

    public function test_only_members_of_the_organization_are_listed(): void
    {
        $other = $this->otherOrganization();
        $foreigner = User::factory()->create(['role' => 'user']);
        Team::factory()->create(['user_id' => $foreigner->id, 'organization_id' => $other->id, 'role' => 'agent', 'created_by' => $this->owner->id]);

        $ids = collect((new AgentPerformanceService($this->organization->id))->presence())->pluck('user_id');

        $this->assertTrue($ids->contains($this->owner->id));
        $this->assertFalse($ids->contains($foreigner->id));
    }

    /** النبضة في شركة لا تُظهره متصلاً في أخرى. */
    public function test_presence_is_scoped_per_organization(): void
    {
        $other = $this->otherOrganization();
        AgentPerformanceService::markPresent($other->id, $this->owner->id);

        $this->assertTrue($this->presenceOf($this->owner, $other)['online']);
        $this->assertFalse($this->presenceOf($this->owner, $this->organization)['online']);
    }

    /** نقطة الحالة والجدول الكامل لا يختلفان: كلاهما من الحساب نفسه. */
    public function test_the_status_endpoint_and_the_full_table_agree(): void
    {
        $online = $this->member();
        $offline = $this->member();
        $messenger = $this->member();
        $this->pingedAt($this->organization, $online, now()->subMinute());
        $this->pingedAt($this->organization, $offline, now()->subMinutes(30));
        $this->chat($this->contact(), ['type' => 'outbound', 'user_id' => $messenger->id, 'created_at' => now()->subMinute()]);

        $service = new AgentPerformanceService($this->organization->id);
        $table = collect($service->metrics(now()->subDays(29)->startOfDay(), now()->endOfDay())['agents'])->keyBy('user_id');
        $status = collect($service->presence())->keyBy('user_id');

        foreach ([$online, $offline, $messenger, $this->owner] as $user) {
            $this->assertSame(
                $table[$user->id]['online'],
                $status[$user->id]['online'],
                "الحالتان تختلفان للمستخدم {$user->id}"
            );
        }
        $this->assertTrue($status[$online->id]['online']);
        $this->assertFalse($status[$offline->id]['online']);
        $this->assertTrue($status[$messenger->id]['online']);
    }

    // ------------------------------------------------- نقطة الحالة

    public function test_the_owner_gets_the_status_of_every_member(): void
    {
        $agent = $this->member();
        $this->pingedAt($this->organization, $agent, now()->subMinute());
        $this->asOwner();

        $response = $this->getJson('/performance/status');

        $response->assertOk()->assertJsonStructure(['agents' => [['user_id', 'online', 'last_activity_at']]]);
        $row = collect($response->json('agents'))->firstWhere('user_id', $agent->id);
        $this->assertTrue($row['online']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_an_agent_cannot_read_the_status(): void
    {
        $agent = $this->member('agent');
        $this->actingAs($agent);
        session(['current_organization' => $this->organization->id]);

        $this->get('/performance/status')->assertForbidden();
    }

    public function test_the_status_needs_the_feature_in_the_plan(): void
    {
        $this->planWithPerformance($this->organization, false);
        $this->asOwner();

        $this->get('/performance/status')->assertForbidden();
    }

    public function test_a_guest_cannot_read_the_status(): void
    {
        $this->getJson('/performance/status')->assertUnauthorized();
    }

    // ------------------------------------------------- فتح الصفحة

    /** المدير الذي يفتح الصفحة لا يراها تقول إنه «غير متصل». */
    public function test_opening_the_page_shows_the_viewer_as_online(): void
    {
        $this->asOwner();

        $response = $this->get('/performance');
        $response->assertOk();

        $agents = collect($response->viewData('page')['props']['metrics']['agents']);
        $this->assertTrue($agents->firstWhere('user_id', $this->owner->id)['online'], 'ظهر مَن فتح الصفحة غير متصل.');
    }

    // ------------------------------------------------- التبديل والدخول

    public function test_switching_organization_marks_the_user_present_in_the_new_one(): void
    {
        $target = $this->otherOrganization();
        $this->asOwner();
        $this->assertNull($this->activity($target, $this->owner));

        $this->post('/select-organization', ['uuid' => $target->uuid])->assertRedirect('/dashboard');

        $row = $this->activity($target, $this->owner);
        $this->assertNotNull($row, 'لم يُثبَّت الحضور في الشركة الجديدة عند التبديل.');
        $this->assertEqualsWithDelta(now()->timestamp, strtotime($row->last_ping_at), 5);
        $this->assertSame(0, (int) $row->active_seconds);
        $this->assertTrue($this->presenceOf($this->owner, $target)['online']);
    }

    public function test_switching_into_a_company_without_the_feature_records_nothing(): void
    {
        $target = $this->otherOrganization(withFeature: false);
        $this->asOwner();

        $this->post('/select-organization', ['uuid' => $target->uuid])->assertRedirect('/dashboard');

        $this->assertNull($this->activity($target, $this->owner));
    }

    public function test_switching_into_a_company_the_user_does_not_belong_to_records_nothing(): void
    {
        $target = $this->otherOrganization(ownerIsMember: false);
        $this->asOwner();

        $this->post('/select-organization', ['uuid' => $target->uuid])->assertRedirect(route('user.organization.index'));

        $this->assertNull($this->activity($target, $this->owner));
    }

    public function test_the_mobile_switch_marks_the_user_present(): void
    {
        $target = $this->otherOrganization();
        $this->actingAs($this->owner, 'sanctum');

        $this->postJson('/api/v1/auth/set-current-organization', ['organization_id' => $target->id])->assertOk();

        $this->assertNotNull($this->activity($target, $this->owner), 'التبديل من التطبيق لم يثبّت الحضور.');
    }

    public function test_the_web_login_marks_the_user_present(): void
    {
        $this->assertNull($this->activity($this->organization, $this->owner));

        $response = $this->post('/login', ['email' => $this->owner->email, 'password' => 'password']);

        $response->assertRedirect();
        $this->assertNotNull($this->activity($this->organization, $this->owner), 'الدخول لم يثبّت الحضور.');
    }

    public function test_the_mobile_login_marks_the_user_present(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'password',
            'device_name' => 'Test Device',
        ]);

        $response->assertOk();
        $this->assertNotNull($this->activity($this->organization, $this->owner), 'الدخول من التطبيق لم يثبّت الحضور.');
    }
}
