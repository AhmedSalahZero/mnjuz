<?php

namespace Tests\Feature;

use App\Jobs\ProcessTicketAssignmentJob;
use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\AgentPerformanceService;
use App\Services\AgentPresence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cycle\CycleTestCase;

/**
 * الإسناد التلقائي (Auto / Round robin) للمتصلين وحدهم.
 *
 * العطل: الإسناد كان يوزّع المحادثات على كل أعضاء الفريق النشطين، فتصل
 * المحادثة لموظف غير متصل وتبقى بلا ردّ.
 *
 * الإصلاح: يُرشَّح المرشّحون بتعريف «متصل» نفسه الذي تعرضه صفحة الأداء (تبويب أو
 * تطبيق نبض خلال النافذة — AgentPresence). إن لم يكن أحد متصلاً تبقى المحادثة بلا موظف في
 * Unassigned. وإن لم يكن الحضور مُتتبَّعاً أصلاً (ميزة الأداء مقفولة) يبقى
 * السلوك القديم، وإلا لما وصلت محادثة لأحد.
 */
class TicketAutoAssignmentOnlineTest extends CycleTestCase
{
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planWithPerformance(true);
        $this->mode('auto');
    }

    // ------------------------------------------------- التجهيز

    private function planWithPerformance(bool $enabled): void
    {
        Subscription::where('organization_id', $this->organization->id)->first()->plan->update([
            'metadata' => json_encode(['message_limit' => -1] + ($enabled ? ['agent_performance' => true] : [])),
        ]);
    }

    private function mode(?string $mode, bool $reassignOnReopen = false, ?bool $legacyAutoAssignment = null): void
    {
        $metadata = json_decode($this->organization->fresh()->metadata, true);
        $metadata['tickets'] = array_merge($metadata['tickets'] ?? [], [
            'active' => true,
            'auto_assignment' => $legacyAutoAssignment ?? in_array($mode, ['auto', 'round_robin'], true),
            'reassign_reopened_chats' => $reassignOnReopen,
            'allow_agents_to_view_all_chats' => false,
        ]);

        if ($mode === null) {
            unset($metadata['tickets']['assignment_mode']);
        } else {
            $metadata['tickets']['assignment_mode'] = $mode;
        }

        $this->organization->update(['metadata' => json_encode($metadata)]);
        Cache::forget("org_settings_{$this->organization->id}");
    }

    private function agent(array $team = [], ?Organization $organization = null): User
    {
        $user = User::factory()->create(['role' => 'user']);
        Team::factory()->create(array_merge([
            'user_id' => $user->id,
            'organization_id' => ($organization ?? $this->organization)->id,
            'role' => 'agent',
            'created_by' => $this->owner->id,
        ], $team));

        return $user;
    }

    /** تبويبه نبض قبل $secondsAgo ثانية. داخل النافذة ⇒ متصل. */
    private function pinged(User $user, int $secondsAgo = 10, ?Organization $organization = null): void
    {
        $at = now()->subSeconds($secondsAgo);

        DB::table('agent_presence_clients')->insert([
            'organization_id' => ($organization ?? $this->organization)->id,
            'user_id' => $user->id,
            'client_id' => 'tab-' . $user->id,
            'platform' => AgentPresence::WEB,
            'last_seen_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        DB::table('agent_activity')->insert([
            'organization_id' => ($organization ?? $this->organization)->id,
            'user_id' => $user->id,
            'activity_date' => $at->toDateString(),
            'active_seconds' => 0,
            'last_ping_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function newContact(): Contact
    {
        return $this->contact(['phone' => '+9665' . str_pad((string) ++$this->phone, 8, '0', STR_PAD_LEFT)]);
    }

    /** محادثات مفتوحة مُسندة للموظف: «انشغاله» عند Auto. */
    private function busyWith(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ChatTicket::withoutEvents(fn () => ChatTicket::create([
                'contact_id' => $this->newContact()->id,
                'assigned_to' => $user->id,
                'status' => 'open',
                'is_latest' => true,
                'assigned_seen' => true,
            ]));
        }
    }

    /** أول رسالة من عميل جديد: ما يحدث في ProcessIncomingMessageJob. */
    private function assignNewConversation(): ChatTicket
    {
        $contact = $this->newContact();
        ProcessTicketAssignmentJob::dispatchSync($contact->id, $this->organization->id, true);

        return ChatTicket::where('contact_id', $contact->id)->where('is_latest', true)->firstOrFail();
    }

    private function pointer(): ?int
    {
        $metadata = json_decode($this->organization->fresh()->metadata, true);
        $value = $metadata['tickets']['round_robin_last_user_id'] ?? null;

        return $value === null ? null : (int) $value;
    }

    private function setPointer(User $user): void
    {
        $metadata = json_decode($this->organization->fresh()->metadata, true);
        $metadata['tickets']['round_robin_last_user_id'] = $user->id;
        $this->organization->update(['metadata' => json_encode($metadata)]);
        Cache::forget("org_settings_{$this->organization->id}");
    }

    // ------------------------------------------------- Auto

    /** البلاغ نفسه: الأقل انشغالاً غير متصل ⇒ لا تصله المحادثة. */
    public function test_auto_skips_an_offline_agent_even_when_he_is_the_least_busy(): void
    {
        $online = $this->agent();
        $offline = $this->agent();
        $this->pinged($online);
        $this->busyWith($online, 3);

        $ticket = $this->assignNewConversation();

        $this->assertSame($online->id, (int) $ticket->assigned_to);
        $this->assertNotSame($offline->id, (int) $ticket->assigned_to);
    }

    public function test_auto_picks_the_least_busy_among_online_agents(): void
    {
        $busy = $this->agent();
        $free = $this->agent();
        $this->pinged($busy);
        $this->pinged($free);
        $this->busyWith($busy, 2);
        $this->busyWith($free, 1);

        $this->assertSame($free->id, (int) $this->assignNewConversation()->assigned_to);
    }

    public function test_auto_spreads_new_conversations_between_online_agents(): void
    {
        $first = $this->agent();
        $second = $this->agent();
        $this->agent(); // غير متصل
        $this->pinged($first);
        $this->pinged($second);

        $assignees = [];
        for ($i = 0; $i < 4; $i++) {
            $assignees[] = (int) $this->assignNewConversation()->assigned_to;
        }

        sort($assignees);
        $expected = [$first->id, $first->id, $second->id, $second->id];
        sort($expected);
        $this->assertSame($expected, $assignees);
    }

    /** قرار المنتج: لا أحد متصلاً ⇒ Unassigned، لا لموظف غير متصل. */
    public function test_auto_leaves_the_conversation_unassigned_when_nobody_is_online(): void
    {
        $agent = $this->agent();
        $this->pinged($agent, AgentPresence::ONLINE_WINDOW_SECONDS + 30);

        $ticket = $this->assignNewConversation();

        $this->assertNull($ticket->assigned_to);
        $this->assertTrue((bool) $ticket->assigned_seen);
        $this->assertSame('open', $ticket->status);
        $this->assertSame(1, DB::table('chat_ticket_logs')->where('contact_id', $ticket->contact_id)->count());
    }

    public function test_a_ping_inside_the_window_counts_and_one_outside_does_not(): void
    {
        $stale = $this->agent();
        $fresh = $this->agent();
        $this->pinged($stale, AgentPresence::ONLINE_WINDOW_SECONDS + 30);
        $this->pinged($fresh, AgentPresence::ONLINE_WINDOW_SECONDS - 30);
        $this->busyWith($fresh, 5);

        $this->assertSame($fresh->id, (int) $this->assignNewConversation()->assigned_to);
    }

    /**
     * رسالة باسمه ليست حضوراً: رسائل الحملة تُسجَّل باسم منشئها، فكانت
     * المحادثات تُسنَد إليه وهو غائب طوال إرسال حملته.
     */
    public function test_a_message_sent_in_someones_name_does_not_make_him_assignable(): void
    {
        $agent = $this->agent();
        $this->chat($this->newContact(), ['user_id' => $agent->id, 'created_at' => now()->subSeconds(10)]);

        $this->assertNull($this->assignNewConversation()->assigned_to);
    }

    /** من يرسل من التطبيق حاضرٌ بجلسة تطبيقه ولو لم ينبض بعد (نسخة قديمة). */
    public function test_sending_from_the_app_makes_the_agent_assignable(): void
    {
        $agent = $this->agent();
        AgentPerformanceService::markPresent($this->organization->id, $agent->id, AgentPresence::MOBILE, 'mobile:1', '1');

        $this->assertSame($agent->id, (int) $this->assignNewConversation()->assigned_to);
    }

    public function test_a_suspended_or_removed_member_is_never_picked_even_when_online(): void
    {
        $suspended = $this->agent(['status' => 'suspended']);
        $removed = $this->agent();
        Team::where('user_id', $removed->id)->delete();
        $this->pinged($suspended);
        $this->pinged($removed);

        $this->assertNull($this->assignNewConversation()->assigned_to);

        $active = $this->agent();
        $this->pinged($active);

        $this->assertSame($active->id, (int) $this->assignNewConversation()->assigned_to);
    }

    public function test_being_online_in_another_company_does_not_count_here(): void
    {
        $agent = $this->agent();
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        Team::factory()->create(['user_id' => $agent->id, 'organization_id' => $other->id, 'role' => 'agent', 'created_by' => $this->owner->id]);
        $this->pinged($agent, 10, $other);

        $this->assertNull($this->assignNewConversation()->assigned_to);
    }

    /** المنظمات القديمة: auto_assignment = true بلا assignment_mode تُعامَل كـ Auto. */
    public function test_the_legacy_auto_setting_follows_the_same_rule(): void
    {
        $this->mode(null, false, true);
        $online = $this->agent();
        $this->agent();
        $this->pinged($online);
        $this->busyWith($online, 4);

        $this->assertSame($online->id, (int) $this->assignNewConversation()->assigned_to);
    }

    // ------------------------------------------------- Round robin

    public function test_round_robin_skips_offline_agents_and_keeps_the_order(): void
    {
        $this->mode('round_robin');
        $first = $this->agent();
        $second = $this->agent();
        $third = $this->agent();
        $this->pinged($first);
        $this->pinged($third);

        $order = [];
        for ($i = 0; $i < 4; $i++) {
            $order[] = (int) $this->assignNewConversation()->assigned_to;
        }

        // المالك (أول عضو) غير متصل أيضاً فيُتخطّى.
        $this->assertSame([$first->id, $third->id, $first->id, $third->id], $order);
        $this->assertNotContains($second->id, $order);
    }

    /** الدور يكمل بعد من صار غير متصل، ولا يعود إلى الأول. */
    public function test_round_robin_continues_after_an_agent_who_went_offline(): void
    {
        $this->mode('round_robin');
        $first = $this->agent();
        $second = $this->agent();
        $third = $this->agent();
        $this->pinged($first);
        $this->pinged($third);
        $this->setPointer($second);

        $this->assertSame($third->id, (int) $this->assignNewConversation()->assigned_to);
        $this->assertSame($third->id, $this->pointer());
    }

    public function test_round_robin_wraps_around_past_offline_agents_at_the_end(): void
    {
        $this->mode('round_robin');
        $first = $this->agent();
        $second = $this->agent();
        $this->agent(); // الثالث غير متصل
        $this->pinged($first);
        $this->pinged($second);
        $this->setPointer($second);

        $this->assertSame($first->id, (int) $this->assignNewConversation()->assigned_to);
    }

    public function test_round_robin_with_nobody_online_leaves_it_unassigned_and_keeps_the_turn(): void
    {
        $this->mode('round_robin');
        $first = $this->agent();
        $second = $this->agent();
        $this->setPointer($first);

        $ticket = $this->assignNewConversation();

        $this->assertNull($ticket->assigned_to);
        $this->assertTrue((bool) $ticket->assigned_seen);
        $this->assertSame($first->id, $this->pointer());

        // يعود الثاني: الدور يكمل من حيث توقّف.
        $this->pinged($second);
        $this->assertSame($second->id, (int) $this->assignNewConversation()->assigned_to);
    }

    // ------------------------------------------------- إعادة الفتح

    private function closedConversation(?User $assignee): Contact
    {
        $contact = $this->newContact();
        ChatTicket::withoutEvents(fn () => ChatTicket::create([
            'contact_id' => $contact->id,
            'assigned_to' => $assignee?->id,
            'status' => 'closed',
            'is_latest' => true,
            'assigned_seen' => true,
        ]));

        return $contact;
    }

    public function test_reopening_reassigns_to_an_online_agent(): void
    {
        $this->mode('auto', true);
        $offline = $this->agent();
        $online = $this->agent();
        $this->pinged($online);
        $contact = $this->closedConversation($offline);

        ProcessTicketAssignmentJob::dispatchSync($contact->id, $this->organization->id, false);

        $ticket = ChatTicket::where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame('open', $ticket->status);
        $this->assertSame($online->id, (int) $ticket->assigned_to);
        $this->assertFalse((bool) $ticket->assigned_seen);
    }

    public function test_reopening_with_nobody_online_leaves_it_unassigned(): void
    {
        $this->mode('round_robin', true);
        $offline = $this->agent();
        $contact = $this->closedConversation($offline);

        ProcessTicketAssignmentJob::dispatchSync($contact->id, $this->organization->id, false);

        $ticket = ChatTicket::where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame('open', $ticket->status);
        $this->assertNull($ticket->assigned_to);
        $this->assertTrue((bool) $ticket->assigned_seen);
    }

    public function test_reopening_without_reassignment_keeps_the_previous_agent(): void
    {
        $this->mode('auto', false);
        $offline = $this->agent();
        $contact = $this->closedConversation($offline);

        ProcessTicketAssignmentJob::dispatchSync($contact->id, $this->organization->id, false);

        $this->assertSame($offline->id, (int) ChatTicket::where('contact_id', $contact->id)->value('assigned_to'));
    }

    /**
     * تذكرة مغلقة بلا موظّف — ما يُنشئه استيراد سجلّ المحادثات — تُسند عند
     * إعادة فتحها ولو كانت «إعادة الإسناد» مطفأة: لا موظّف سابق يُحافَظ عليه.
     */
    public function test_reopening_an_unassigned_ticket_assigns_it_even_without_reassignment(): void
    {
        $this->mode('auto', false);
        $online = $this->agent();
        $this->pinged($online);
        $contact = $this->closedConversation(null);

        ProcessTicketAssignmentJob::dispatchSync($contact->id, $this->organization->id, false);

        $ticket = ChatTicket::where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame('open', $ticket->status);
        $this->assertSame($online->id, (int) $ticket->assigned_to);
        $this->assertFalse((bool) $ticket->assigned_seen);
    }

    public function test_reopening_an_unassigned_ticket_without_auto_assignment_leaves_it_unassigned(): void
    {
        $this->mode(null, false, false);
        $online = $this->agent();
        $this->pinged($online);
        $contact = $this->closedConversation(null);

        ProcessTicketAssignmentJob::dispatchSync($contact->id, $this->organization->id, false);

        $this->assertNull(ChatTicket::where('contact_id', $contact->id)->value('assigned_to'));
    }

    // ------------------------------------------------- بلا تتبّع حضور

    /** الميزة مقفولة ⇒ لا نبضات تُسجَّل أصلاً، فالترشيح يُفرغ الإسناد. */
    public function test_without_presence_tracking_auto_assigns_like_before(): void
    {
        $this->planWithPerformance(false);
        $agent = $this->agent();
        $this->busyWith($this->owner, 1);

        $this->assertSame($agent->id, (int) $this->assignNewConversation()->assigned_to);
    }

    public function test_without_presence_tracking_round_robin_rotates_like_before(): void
    {
        $this->planWithPerformance(false);
        $this->mode('round_robin');
        $agent = $this->agent();

        $this->assertSame(
            [$this->owner->id, $agent->id, $this->owner->id],
            [
                (int) $this->assignNewConversation()->assigned_to,
                (int) $this->assignNewConversation()->assigned_to,
                (int) $this->assignNewConversation()->assigned_to,
            ]
        );
    }

    public function test_an_expired_subscription_falls_back_to_the_old_behaviour(): void
    {
        Subscription::where('organization_id', $this->organization->id)->update(['valid_until' => now()->subDay()]);
        $agent = $this->agent();
        $this->busyWith($this->owner, 1);

        $this->assertSame($agent->id, (int) $this->assignNewConversation()->assigned_to);
    }

    // ------------------------------------------------- أوضاع بلا إسناد

    public function test_manual_and_shared_modes_never_assign_even_with_online_agents(): void
    {
        $agent = $this->agent();
        $this->pinged($agent);

        foreach (['manual', 'shared'] as $mode) {
            $this->mode($mode);
            $this->assertNull($this->assignNewConversation()->assigned_to, $mode);
        }
    }

    // ------------------------------------------------- تعريف واحد للاتصال

    /** الإسناد وصفحة الأداء يقولان الشيء نفسه عن كل موظف. */
    public function test_online_ids_match_the_performance_page(): void
    {
        $pinged = $this->agent();
        $stale = $this->agent();
        $messaged = $this->agent();
        $this->agent();
        $this->pinged($pinged);
        $this->pinged($stale, AgentPresence::ONLINE_WINDOW_SECONDS + 30);
        $this->chat($this->newContact(), ['user_id' => $messaged->id, 'created_at' => now()->subSeconds(30)]);

        $service = new AgentPerformanceService($this->organization->id);
        $fromPage = collect($service->presence())->where('online', true)->pluck('user_id')->sort()->values()->all();
        $online = $service->onlineUserIds();
        sort($online);

        $this->assertSame($fromPage, $online);
        $this->assertSame([$pinged->id], $online);
    }

    /** تبويبان: واحدٌ منقطع والآخر حيّ ⇒ متصل. */
    public function test_one_live_client_is_enough(): void
    {
        $agent = $this->agent();
        DB::table('agent_presence_clients')->insert([
            'organization_id' => $this->organization->id,
            'user_id' => $agent->id,
            'client_id' => 'old-tab',
            'platform' => AgentPresence::WEB,
            'last_seen_at' => now()->subMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->pinged($agent, 5);

        $this->assertSame([$agent->id], (new AgentPerformanceService($this->organization->id))->onlineUserIds());
    }

    // ------------------------------------------------- نص الإعدادات

    public function test_the_settings_page_describes_the_online_rule_in_every_language(): void
    {
        $keys = [
            'Distribute conversations among online team members. If no one is online, they wait in Unassigned.',
            'Assign conversations in order to online agents only: agent 1, then 2, then 3, then back to 1.',
        ];

        $page = file_get_contents(base_path('resources/js/Pages/User/Settings/Ticket.vue'));
        foreach ($keys as $key) {
            $this->assertStringContainsString("'{$key}'", $page);
        }

        foreach (['en', 'default_en', 'ar', 'fr', 'es', 'tr', 'sw'] as $locale) {
            $translations = json_decode(file_get_contents(base_path("lang/{$locale}.json")), true);
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $translations, "{$locale}: {$key}");
                $this->assertNotSame('', trim($translations[$key]), "{$locale}: {$key}");
            }
        }
    }
}
