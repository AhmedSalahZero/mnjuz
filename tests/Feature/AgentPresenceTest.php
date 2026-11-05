<?php

namespace Tests\Feature;

use App\Events\AgentPresenceChanged;
use App\Jobs\ConfirmAgentOffline;
use App\Models\Addon;
use App\Models\Chat;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Services\AgentPerformanceService;
use App\Services\AgentPresence;
use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
 *
 * ثم صار الحضور لحظياً: كل تبويب وجلسة تطبيق «عميل» ينبض، والموظف متصل ما دام له
 * عميل حيّ. التغيّر يُبثّ للمدير (AgentPresenceChanged): أول نبضة، والمغادرة
 * الصريحة (إغلاق التبويب، الخروج، الخلفية)، وانقطاع النبضة (المنظّف).
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

    /** عميل (تبويب أو تطبيق) نبض آخر مرة في الوقت المطلوب. */
    private function clientSeenAt(
        Organization $organization,
        User $user,
        \DateTimeInterface $at,
        string $clientId = 'tab-1',
        string $platform = AgentPresence::WEB,
        ?string $sessionKey = null,
    ): void {
        DB::table('agent_presence_clients')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'client_id' => $clientId,
            'platform' => $platform,
            'session_key' => $sessionKey,
            'last_seen_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function clients(User $user, ?Organization $organization = null): \Illuminate\Support\Collection
    {
        return DB::table('agent_presence_clients')
            ->where('user_id', $user->id)
            ->when($organization, fn ($q) => $q->where('organization_id', $organization->id))
            ->get();
    }

    /** توكن تطبيق حقيقي (لا actingAs): الحضور يُنسب إلى التوكن نفسه. */
    private function mobileToken(User $user, ?Organization $organization = null): string
    {
        if (!Addon::where('name', 'Mobile App')->exists()) {
            Addon::factory()->create(['name' => 'Mobile App', 'status' => 1, 'is_active' => 1]);
        }

        $user->forceFill(['current_mobile_organization_id' => ($organization ?? $this->organization)->id])->save();

        return $user->createToken('Test Device')->plainTextToken;
    }

    private function heartbeat(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/performance/heartbeat', $body + ['client_id' => 'tab-1', 'visible' => true]);
    }

    private function wentOffline(User $user, ?Organization $organization = null): \Closure
    {
        $organizationId = ($organization ?? $this->organization)->id;

        return fn (AgentPresenceChanged $e) => !$e->online && $e->userId === $user->id && $e->organizationId === $organizationId;
    }

    private function cameOnline(User $user, ?Organization $organization = null): \Closure
    {
        $organizationId = ($organization ?? $this->organization)->id;

        return fn (AgentPresenceChanged $e) => $e->online && $e->userId === $user->id && $e->organizationId === $organizationId;
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

    public function test_a_live_client_is_online_and_a_silent_one_is_offline(): void
    {
        $fresh = $this->member();
        $stale = $this->member();
        $this->clientSeenAt($this->organization, $fresh, now()->subSeconds(AgentPresence::ONLINE_WINDOW_SECONDS - 20));
        $this->clientSeenAt($this->organization, $stale, now()->subSeconds(AgentPresence::ONLINE_WINDOW_SECONDS + 20));

        $this->assertTrue($this->presenceOf($fresh)['online'], 'نبضة داخل النافذة.');
        $this->assertFalse($this->presenceOf($stale)['online'], 'نبضة خارج النافذة.');
    }

    /** كانت النافذة خمس دقائق، فيبقى من أغلق كل شيء «متصلاً» طوالها. */
    public function test_a_ping_minutes_ago_is_no_longer_online(): void
    {
        $agent = $this->member();
        $this->pingedAt($this->organization, $agent, now()->subMinutes(3));

        $row = $this->presenceOf($agent);

        $this->assertFalse($row['online']);
        $this->assertNotNull($row['last_activity_at'], 'آخر نشاط يبقى ظاهراً.');
    }

    public function test_a_user_who_never_pinged_is_offline(): void
    {
        $agent = $this->member();

        $row = $this->presenceOf($agent);

        $this->assertFalse($row['online']);
        $this->assertNull($row['last_activity_at']);
    }

    /**
     * رسالة باسمه ليست حضوراً: رسائل الحملة تُسجَّل باسم منشئها، فكان يظهر
     * «متصلاً» — وتُسنَد إليه المحادثات — طوال إرسالها ولو في منتصف الليل.
     */
    public function test_a_message_sent_in_someones_name_is_not_presence(): void
    {
        $creator = $this->member('manager');
        $this->chat($this->contact(), ['type' => 'outbound', 'user_id' => $creator->id, 'created_at' => now()->subSeconds(10)]);

        $this->assertFalse($this->presenceOf($creator)['online']);
        $this->assertNotContains($creator->id, (new AgentPerformanceService($this->organization->id))->onlineUserIds());
    }

    /** الإسناد التلقائي يرى المتصلين أنفسهم الذين تعرضهم الصفحة. */
    public function test_auto_assignment_sees_the_same_online_agents(): void
    {
        $online = $this->member();
        $offline = $this->member();
        $this->clientSeenAt($this->organization, $online, now()->subSeconds(5));
        $this->clientSeenAt($this->organization, $offline, now()->subMinutes(3));

        $ids = (new AgentPerformanceService($this->organization->id))->onlineUserIds();

        $this->assertContains($online->id, $ids);
        $this->assertNotContains($offline->id, $ids);
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
        $this->clientSeenAt($this->organization, $online, now()->subSeconds(10));
        $this->clientSeenAt($this->organization, $offline, now()->subMinutes(30));
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
        $this->assertFalse($status[$messenger->id]['online']);
        $this->assertSame(1, $table[$messenger->id]['messages_sent'], 'الرسالة تُعدّ وإن لم تكن حضوراً.');
    }

    /** لحظة القراءة ترافق كل صفّ: الواجهة تُسقط بها ما هو أقدم من حدثٍ طبّقته. */
    public function test_every_status_row_carries_the_read_time(): void
    {
        $before = AgentPresence::clock();
        $rows = (new AgentPerformanceService($this->organization->id))->presence();
        $after = AgentPresence::clock();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual($before, $row['at']);
            $this->assertLessThanOrEqual($after, $row['at']);
        }
    }

    // ------------------------------------------------- نبضة التبويب ومغادرته

    public function test_a_tab_heartbeat_makes_the_user_online_and_announces_it_once(): void
    {
        Event::fake([AgentPresenceChanged::class]);
        $this->asOwner();

        $this->heartbeat()->assertOk()->assertJson(['interval' => AgentPresence::HEARTBEAT_SECONDS]);
        $this->heartbeat()->assertOk();

        $this->assertTrue($this->presenceOf($this->owner)['online']);
        Event::assertDispatchedTimes(AgentPresenceChanged::class, 1);
        Event::assertDispatched(AgentPresenceChanged::class, $this->cameOnline($this->owner));
    }

    /** الحدث على قناة المنظمة الخاصة، باسمٍ ثابت يستمع إليه التطبيق. */
    public function test_the_event_goes_to_the_organizations_private_channel(): void
    {
        $event = new AgentPresenceChanged($this->organization->id, $this->owner->id, true, now()->toDateTimeString(), AgentPresence::clock());

        $this->assertSame('private-agents.presence.ch' . $this->organization->id, $event->broadcastOn()[0]->name);
        $this->assertSame('AgentPresenceChanged', $event->broadcastAs());
        $this->assertSame(['user_id', 'online', 'last_activity_at', 'at'], array_keys($event->broadcastWith()));
        $this->assertSame('high', $event->queue);
    }

    public function test_an_agent_can_beat_and_leave(): void
    {
        $agent = $this->member('agent');
        $this->actingAs($agent);
        session(['current_organization' => $this->organization->id]);

        $this->heartbeat()->assertOk();
        $this->assertTrue($this->presenceOf($agent)['online']);

        $this->postJson('/performance/offline', ['client_id' => 'tab-1'])->assertNoContent();
        $this->assertFalse($this->presenceOf($agent)['online']);
    }

    public function test_closing_the_last_tab_announces_offline(): void
    {
        Event::fake([AgentPresenceChanged::class]);
        $this->asOwner();

        $this->heartbeat()->assertOk();
        $this->postJson('/performance/offline', ['client_id' => 'tab-1', 'organization_id' => $this->organization->id])->assertNoContent();

        $this->assertFalse($this->presenceOf($this->owner)['online']);
        Event::assertDispatched(AgentPresenceChanged::class, $this->wentOffline($this->owner));
    }

    public function test_closing_one_of_two_tabs_keeps_the_user_online(): void
    {
        Event::fake([AgentPresenceChanged::class]);
        $this->asOwner();

        $this->heartbeat(['client_id' => 'tab-1'])->assertOk();
        $this->heartbeat(['client_id' => 'tab-2'])->assertOk();
        $this->postJson('/performance/offline', ['client_id' => 'tab-1'])->assertNoContent();

        $this->assertTrue($this->presenceOf($this->owner)['online']);
        Event::assertNotDispatched(AgentPresenceChanged::class, $this->wentOffline($this->owner));
    }

    /** المغادرة تُؤكَّد بعد مهلة: إعادة التحميل تغادر وتنبض في اللحظة نفسها. */
    public function test_leaving_is_confirmed_after_a_grace_period(): void
    {
        Bus::fake([ConfirmAgentOffline::class]);
        $this->asOwner();

        $this->heartbeat()->assertOk();
        $this->postJson('/performance/offline', ['client_id' => 'tab-1'])->assertNoContent();

        Bus::assertDispatched(ConfirmAgentOffline::class, function (ConfirmAgentOffline $job) {
            return $job->organizationId === $this->organization->id
                && $job->userId === $this->owner->id
                && $job->queue === 'high'
                && $job->delay !== null;
        });
    }

    public function test_a_reload_within_the_grace_period_announces_nothing(): void
    {
        Event::fake([AgentPresenceChanged::class]);
        // التبويب أُعيد تحميله: القديم غادر، والجديد نبض قبل انقضاء المهلة.
        $this->clientSeenAt($this->organization, $this->owner, now(), 'tab-after-reload');

        (new ConfirmAgentOffline($this->organization->id, $this->owner->id))->handle();

        Event::assertNotDispatched(AgentPresenceChanged::class);
    }

    /** المنظمة من التبويب لا من الجلسة: شركتان في تبويبين، كلٌّ ينبض لشركته. */
    public function test_the_heartbeat_counts_for_the_tabs_organization(): void
    {
        $other = $this->otherOrganization();
        $this->asOwner();

        $this->heartbeat(['organization_id' => $other->id])->assertOk();

        $this->assertTrue($this->presenceOf($this->owner, $other)['online']);
        $this->assertFalse($this->presenceOf($this->owner, $this->organization)['online']);
    }

    public function test_a_heartbeat_for_a_foreign_organization_is_refused(): void
    {
        $foreign = $this->otherOrganization(ownerIsMember: false);
        $this->asOwner();

        $this->heartbeat(['organization_id' => $foreign->id])->assertForbidden();

        $this->assertCount(0, $this->clients($this->owner, $foreign));
        $this->assertNull($this->activity($foreign, $this->owner));
    }

    public function test_a_tab_that_switches_organization_leaves_the_previous_one(): void
    {
        Event::fake([AgentPresenceChanged::class]);
        $other = $this->otherOrganization();
        $this->asOwner();

        $this->heartbeat(['organization_id' => $this->organization->id])->assertOk();
        $this->heartbeat(['organization_id' => $other->id])->assertOk();

        $this->assertFalse($this->presenceOf($this->owner, $this->organization)['online']);
        $this->assertTrue($this->presenceOf($this->owner, $other)['online']);
        Event::assertDispatched(AgentPresenceChanged::class, $this->wentOffline($this->owner, $this->organization));
    }

    /** صفحة قديمة مخبّأة (قبل النشر) لا ترسل معرّف التبويب، وتبقى صحيحة. */
    public function test_an_old_cached_page_without_a_client_id_still_counts(): void
    {
        $this->asOwner();

        $this->postJson('/performance/heartbeat', ['visible' => true])->assertOk();

        $this->assertTrue($this->presenceOf($this->owner)['online']);
    }

    public function test_the_heartbeat_rejects_a_malformed_client_id(): void
    {
        $this->asOwner();

        $this->heartbeat(['client_id' => str_repeat('x', 65)])->assertUnprocessable();
        $this->heartbeat(['client_id' => 'bad id!'])->assertUnprocessable();
    }

    public function test_the_heartbeat_records_nothing_without_the_feature(): void
    {
        $this->planWithPerformance($this->organization, false);
        $this->asOwner();

        $this->heartbeat()->assertOk();

        $this->assertCount(0, $this->clients($this->owner));
    }

    // ------------------------------------------------- المنظّف

    public function test_the_sweeper_announces_whoever_stopped_beating(): void
    {
        Event::fake([AgentPresenceChanged::class]);
        $gone = $this->member();
        $here = $this->member();
        $this->clientSeenAt($this->organization, $gone, now()->subSeconds(AgentPresence::ONLINE_WINDOW_SECONDS + 30));
        $this->clientSeenAt($this->organization, $here, now()->subSeconds(10));

        $this->assertSame(1, AgentPresence::sweep());

        Event::assertDispatched(AgentPresenceChanged::class, $this->wentOffline($gone));
        Event::assertNotDispatched(AgentPresenceChanged::class, fn ($e) => $e->userId === $here->id);
        $this->assertCount(0, $this->clients($gone));
        $this->assertTrue($this->presenceOf($here)['online']);
    }

    /** تبويبٌ انهار والتطبيق مفتوح: لا يُعلَن غائباً. */
    public function test_the_sweeper_is_silent_for_someone_still_open_elsewhere(): void
    {
        Event::fake([AgentPresenceChanged::class]);
        $agent = $this->member();
        $this->clientSeenAt($this->organization, $agent, now()->subMinutes(5), 'crashed-tab');
        $this->clientSeenAt($this->organization, $agent, now()->subSeconds(5), 'mobile:1', AgentPresence::MOBILE);

        AgentPresence::sweep();

        Event::assertNotDispatched(AgentPresenceChanged::class);
        $this->assertTrue($this->presenceOf($agent)['online']);
        $this->assertCount(1, $this->clients($agent));
    }

    // ------------------------------------------------- الخروج

    /** الخروج من متصفّح لا يُسقط تبويباً مفتوحاً في متصفّح آخر. */
    public function test_web_logout_drops_only_that_sessions_tabs(): void
    {
        $sessionId = str_repeat('a', 40);
        $this->clientSeenAt($this->organization, $this->owner, now(), 'tab-here', AgentPresence::WEB, $sessionId);
        $this->clientSeenAt($this->organization, $this->owner, now(), 'tab-elsewhere', AgentPresence::WEB, str_repeat('b', 40));

        $this->actingAs($this->owner)
            ->withCookie(config('session.cookie'), $sessionId)
            ->get('/logout')
            ->assertRedirect();

        $this->assertSame(['tab-elsewhere'], $this->clients($this->owner)->pluck('client_id')->all());
    }

    // ------------------------------------------------- التطبيق

    public function test_the_app_heartbeat_and_background_signal(): void
    {
        Event::fake([AgentPresenceChanged::class]);
        $token = $this->mobileToken($this->owner);

        $this->withToken($token)->postJson('/api/v1/performance/heartbeat')->assertOk()
            ->assertJson(['interval' => AgentPresence::HEARTBEAT_SECONDS]);

        $client = $this->clients($this->owner)->first();
        $this->assertNotNull($client);
        $this->assertSame(AgentPresence::MOBILE, $client->platform);
        $this->assertStringStartsWith('mobile:', $client->client_id);
        $this->assertTrue($this->presenceOf($this->owner)['online']);
        Event::assertDispatched(AgentPresenceChanged::class, $this->cameOnline($this->owner));

        $this->withToken($token)->postJson('/api/v1/performance/offline')->assertOk();

        $this->assertFalse($this->presenceOf($this->owner)['online']);
        Event::assertDispatched(AgentPresenceChanged::class, $this->wentOffline($this->owner));
    }

    public function test_app_logout_drops_the_app_but_not_the_web_tab(): void
    {
        $token = $this->mobileToken($this->owner);
        $this->withToken($token)->postJson('/api/v1/performance/heartbeat')->assertOk();
        $this->clientSeenAt($this->organization, $this->owner, now(), 'tab-1', AgentPresence::WEB, str_repeat('c', 40));

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame([AgentPresence::WEB], $this->clients($this->owner)->pluck('platform')->all());
        $this->assertTrue($this->presenceOf($this->owner)['online']);
    }

    public function test_the_app_status_endpoint_is_for_managers(): void
    {
        $agent = $this->member();
        $this->clientSeenAt($this->organization, $agent, now());

        $response = $this->withToken($this->mobileToken($this->owner))->getJson('/api/v1/performance/status');

        $response->assertOk()->assertJsonStructure(['data' => ['agents' => [['user_id', 'online', 'last_activity_at', 'at']]]]);
        $this->assertTrue(collect($response->json('data.agents'))->firstWhere('user_id', $agent->id)['online']);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->mobileToken($agent))->getJson('/api/v1/performance/status')->assertForbidden();
    }

    /** التطبيق يأخذ اسم قناة الحالة مع إعداد البثّ — للمدير وحده. */
    public function test_the_app_gets_the_presence_channel_only_as_a_manager(): void
    {
        $agent = $this->member();

        $this->assertSame(
            'private-agents.presence.ch' . $this->organization->id,
            BroadcastProvider::appConfig($this->organization->id, $this->owner->id)['presence_channel']
        );
        $this->assertNull(BroadcastProvider::appConfig($this->organization->id, $agent->id)['presence_channel']);
    }

    /** القناة تُوقَّع بسرّ مزوّد منشأتها: التحليل يعرف رقمها من اسمها. */
    public function test_the_presence_channel_name_resolves_its_organization(): void
    {
        $id = $this->organization->id;

        $this->assertSame($id, BroadcastProvider::organizationFromChannel('private-agents.presence.ch' . $id));
        $this->assertSame($id, BroadcastProvider::organizationFromChannel('agents.presence.ch' . $id));
    }

    // ------------------------------------------------- نقطة الحالة

    public function test_the_owner_gets_the_status_of_every_member(): void
    {
        $agent = $this->member();
        $this->clientSeenAt($this->organization, $agent, now()->subSeconds(10));
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
