<?php

namespace Tests\Feature\ConversationCycle;

use App\Models\ActivityLog;
use App\Models\ChatTicket;
use App\Models\ChatTicketLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\AgentPresence;
use App\Services\Messaging\Channel;
use Modules\FlowBuilder\Models\FlowUserData;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * خطوة «تحويل إلى موظف» من الباب الحقيقي على كل قناة.
 *
 * العميل يختار القسم من أزرار، فتُسند المحادثة لموظّفه: المتّصل الآن يأخذها
 * ويصل العميلَ ما بعد مخرج «متاح»؛ ومن ليس متّصلاً تُسند إليه مع ذلك
 * (يجدها حين يعود) ويصل العميلَ ما بعد مخرج «غير متاح» — نصّ أو أزرار أو
 * قائمة. باقة المنشأة هنا بلا «أداء الموظفين»: كل اختبار يُثبت أيضاً أن
 * الحضور يعمل لكل الباقات.
 */
class HandoffCycleTest extends ConversationCycleTestCase
{
    private User $ahmed;
    private User $fawaz;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ahmed = $this->member('أحمد');
        $this->fawaz = $this->member('فواز');
        $this->ali = $this->member('علي');
    }

    /**
     * الدعم ← أحمد. «متاح» ← نصّ، «غير متاح» ← ما يُعطى (أزرار افتراضاً).
     *
     * @param  array<int>  $agents
     */
    private function supportFlow(array $agents, ?array $unavailableNode = null, bool $active = true, bool $connectUnavailable = true): void
    {
        $unavailableNode ??= $this->buttonsNode('7', 'أحمد غير متاح الآن. ماذا تفضّل؟', ['انتظار', 'المبيعات']);

        $nodes = [
            $this->startNode('keywords', 'القائمة'),
            $this->buttonsNode('2', 'كيف نساعدك؟', ['الدعم الفني', 'المبيعات']),
            $this->actionNode('5', 'assign_agent', ['user_ids' => $agents], $active),
            $this->textNode('6', 'تم تحويلك، الموظف معك الآن'),
            $unavailableNode,
        ];
        $links = [['1', '2'], ['2', '5', 'a'], ['5', '6', 'available']];

        if ($connectUnavailable) {
            $links[] = ['5', $unavailableNode['id'], 'unavailable'];
        }

        $this->makeFlow($nodes, $links, ['channels' => self::CHANNELS]);
    }

    // ───────────────────── على كل قناة ─────────────────────

    #[DataProvider('channels')]
    public function test_choosing_a_department_hands_the_conversation_to_its_online_agent(string $channel): void
    {
        $this->supportFlow([$this->ahmed->id]);
        $this->online($this->ahmed);

        $this->customerWrites($channel, 'القائمة');
        $this->customerPresses($channel, 'الدعم الفني');

        $customer = $this->customer($channel);
        $this->assertSame($this->ahmed->id, (int) $this->latestTicket($customer)->assigned_to);
        $this->assertSame('تم تحويلك، الموظف معك الآن', last($this->deliveredTexts($channel)));
        $this->assertSame(1, $this->assignmentLines($customer));
    }

    #[DataProvider('channels')]
    public function test_an_offline_agent_still_gets_it_and_the_customer_gets_the_not_available_buttons(string $channel): void
    {
        $this->supportFlow([$this->ahmed->id]);

        $this->customerWrites($channel, 'القائمة');
        $this->customerPresses($channel, 'الدعم الفني');

        $customer = $this->customer($channel);
        // يجدها حين يعود.
        $this->assertSame($this->ahmed->id, (int) $this->latestTicket($customer)->assigned_to);
        $this->assertSame('أحمد غير متاح الآن. ماذا تفضّل؟', last($this->deliveredTexts($channel)));
        $this->assertSame(['انتظار', 'المبيعات'], $this->offeredButtons($channel));
        $this->assertNotContains('تم تحويلك، الموظف معك الآن', $this->deliveredTexts($channel));
    }

    #[DataProvider('channels')]
    public function test_the_not_available_path_can_be_an_interactive_list(string $channel): void
    {
        $this->supportFlow([$this->ahmed->id], $this->listNode('7', 'اختر قسماً آخر', [['المبيعات', 'الحسابات']]));

        $this->customerWrites($channel, 'القائمة');
        $this->customerPresses($channel, 'الدعم الفني');

        $this->assertSame('اختر قسماً آخر', last($this->deliveredTexts($channel)));
    }

    #[DataProvider('channels')]
    public function test_the_not_available_path_can_be_plain_text(string $channel): void
    {
        $this->supportFlow([$this->ahmed->id], $this->textNode('7', 'سيتواصل معك أحمد قريباً'));

        $this->customerWrites($channel, 'القائمة');
        $this->customerPresses($channel, 'الدعم الفني');

        $this->assertSame('سيتواصل معك أحمد قريباً', last($this->deliveredTexts($channel)));
    }

    // ───────────────────── من يأخذها ─────────────────────

    public function test_the_least_busy_online_agent_wins_even_over_an_idle_offline_one(): void
    {
        $this->supportFlow([$this->fawaz->id, $this->ali->id, $this->ahmed->id]);
        $this->busyWith($this->fawaz, 3);
        $this->busyWith($this->ali, 1);
        // أحمد خامل لكنه غير متّصل.
        $this->online($this->fawaz);
        $this->online($this->ali);

        $this->pressSupport();

        $this->assertSame($this->ali->id, $this->assignee());
        $this->assertSame('تم تحويلك، الموظف معك الآن', last($this->deliveredTexts(Channel::WHATSAPP)));
    }

    public function test_when_nobody_chosen_is_online_the_least_busy_of_them_still_gets_it(): void
    {
        $this->supportFlow([$this->fawaz->id, $this->ali->id]);
        $this->busyWith($this->fawaz, 2);
        // متّصل لكنه ليس ممّن اختيروا: لا يُحسب.
        $this->online($this->ahmed);

        $this->pressSupport();

        $this->assertSame($this->ali->id, $this->assignee());
        $this->assertSame('أحمد غير متاح الآن. ماذا تفضّل؟', last($this->deliveredTexts(Channel::WHATSAPP)));
    }

    public function test_a_suspended_or_removed_member_is_never_chosen(): void
    {
        $this->supportFlow([$this->fawaz->id, $this->ali->id]);
        Team::where('user_id', $this->fawaz->id)->update(['status' => 'suspended']);
        $this->online($this->fawaz);
        $this->online($this->ali);
        $this->busyWith($this->ali, 5);

        $this->pressSupport();

        $this->assertSame($this->ali->id, $this->assignee());

        // علي غادر الفريق أيضاً: لا أحد ممّن اختيروا بقي نشطاً. إعادة فتح
        // المحادثة قد تُبقي من كانت معه (سلوك التذاكر القائم)؛ المهمّ أن
        // الخطوة لا تكتب إسناداً جديداً وأن العميل يسلك «غير متاح».
        Team::where('user_id', $this->ali->id)->delete();
        $this->agentCloses($this->contact->fresh());
        $before = $this->assignmentLines($this->contact->fresh());

        $this->pressSupport();

        $this->assertSame($before, $this->assignmentLines($this->contact->fresh()));
        $this->assertNotSame($this->fawaz->id, $this->assignee());
        $this->assertSame('أحمد غير متاح الآن. ماذا تفضّل؟', last($this->deliveredTexts(Channel::WHATSAPP)));
    }

    public function test_when_every_chosen_member_left_nobody_is_assigned_and_not_available_runs(): void
    {
        $this->supportFlow([$this->fawaz->id]);
        Team::where('user_id', $this->fawaz->id)->delete();

        $this->pressSupport();

        $this->assertNull($this->assignee());
        $this->assertSame(0, $this->assignmentLines($this->contact->fresh()));
        $this->assertSame('أحمد غير متاح الآن. ماذا تفضّل؟', last($this->deliveredTexts(Channel::WHATSAPP)));
    }

    public function test_a_member_of_another_organization_is_never_chosen(): void
    {
        $stranger = User::factory()->create(['role' => 'user']);
        $other = Organization::factory()->create(['created_by' => $stranger->id]);
        Team::factory()->create(['user_id' => $stranger->id, 'organization_id' => $other->id, 'role' => 'owner', 'status' => 'active']);
        AgentPresence::touch($other->id, $stranger->id, 'tab-x', AgentPresence::WEB);

        $this->supportFlow([$stranger->id]);

        $this->pressSupport();

        $this->assertNull($this->assignee());
        $this->assertSame('أحمد غير متاح الآن. ماذا تفضّل؟', last($this->deliveredTexts(Channel::WHATSAPP)));
    }

    public function test_being_online_in_another_organization_does_not_count(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->ahmed->id]);
        Team::factory()->create(['user_id' => $this->ahmed->id, 'organization_id' => $other->id, 'role' => 'agent', 'status' => 'active']);
        AgentPresence::touch($other->id, $this->ahmed->id, 'tab-x', AgentPresence::WEB);

        $this->supportFlow([$this->ahmed->id]);

        $this->pressSupport();

        $this->assertSame($this->ahmed->id, $this->assignee());
        $this->assertSame('أحمد غير متاح الآن. ماذا تفضّل؟', last($this->deliveredTexts(Channel::WHATSAPP)));
    }

    // ───────────────────── التذاكر والإسناد السابق ─────────────────────

    public function test_without_ticketing_nothing_is_assigned_and_no_assignment_line_is_written(): void
    {
        $this->settings(['tickets' => ['active' => false]]);
        $this->supportFlow([$this->ahmed->id]);
        $this->online($this->ahmed);

        $this->pressSupport();

        $this->assertSame(0, ChatTicketLog::where('contact_id', $this->contact->id)
            ->where('description', 'like', 'Conversation was assigned to%')->count());
        // المسار يتبع الحضور كما هو.
        $this->assertSame('تم تحويلك، الموظف معك الآن', last($this->deliveredTexts(Channel::WHATSAPP)));
    }

    public function test_the_flow_overrides_round_robin_with_a_single_line_for_its_agent(): void
    {
        $this->settings(['tickets' => ['assignment_mode' => 'round_robin']]);
        $this->supportFlow([$this->ahmed->id]);
        $this->online($this->ahmed);

        $this->customerWrites(Channel::WHATSAPP, 'القائمة');
        $roundRobin = (int) $this->latestTicket($this->contact->fresh())->assigned_to;
        $this->assertNotSame($this->ahmed->id, $roundRobin, 'الدور وقع على أحمد مصادفةً، الاختبار لا يُثبت شيئاً');

        $this->customerPresses(Channel::WHATSAPP, 'الدعم الفني');

        $this->assertSame($this->ahmed->id, $this->assignee());
        $this->assertSame(1, ChatTicketLog::where('contact_id', $this->contact->id)
            ->where('description', 'like', '%' . $this->ahmed->first_name . '%')->count());
    }

    public function test_a_conversation_already_with_a_chosen_agent_stays_with_them(): void
    {
        $this->supportFlow([$this->fawaz->id, $this->ali->id]);
        $this->online($this->fawaz);
        $this->online($this->ali);
        $this->busyWith($this->fawaz, 4);

        $this->customerWrites(Channel::WHATSAPP, 'القائمة');
        $ticket = $this->latestTicket($this->contact->fresh());
        $ticket->update(['assigned_to' => $this->fawaz->id]);

        $this->customerPresses(Channel::WHATSAPP, 'الدعم الفني');

        $this->assertSame($this->fawaz->id, $this->assignee());
        $this->assertSame(0, $this->assignmentLines($this->contact->fresh()));
    }

    public function test_two_transfers_in_a_row_write_one_assignment(): void
    {
        $this->supportFlow([$this->fawaz->id, $this->ali->id]);
        $this->online($this->fawaz);
        $this->online($this->ali);

        $this->pressSupport();
        $first = $this->assignee();

        // الضغطة تتكرّر (زرّ ضُغط مرّتين): القائمة نفسها تُعرض من جديد.
        $this->customerWrites(Channel::WHATSAPP, 'القائمة');
        $this->customerPresses(Channel::WHATSAPP, 'الدعم الفني');

        $this->assertSame($first, $this->assignee());
        $this->assertSame(1, $this->assignmentLines($this->contact->fresh()));
    }

    // ───────────────────── مسار التدفّق ─────────────────────

    public function test_an_unconnected_output_ends_the_flow_and_the_next_message_reaches_basic_replies(): void
    {
        $this->supportFlow([$this->ahmed->id], connectUnavailable: false);
        $this->autoReply('الشحن', 'الشحن خلال يومين');

        $this->pressSupport();

        // لا ردّ بعد التحويل، والإسناد قائم.
        $this->assertSame('كيف نساعدك؟', last($this->deliveredTexts(Channel::WHATSAPP)));
        $this->assertSame($this->ahmed->id, $this->assignee());
        $this->assertNull(FlowUserData::where('contact_id', $this->contact->id)->first());

        $this->customerWrites(Channel::WHATSAPP, 'الشحن');

        $this->assertSame('الشحن خلال يومين', last($this->deliveredTexts(Channel::WHATSAPP)));
    }

    public function test_an_inactive_transfer_step_takes_the_not_available_path_without_assigning(): void
    {
        $this->supportFlow([$this->ahmed->id], active: false);
        $this->online($this->ahmed);

        $this->pressSupport();

        $this->assertNull($this->assignee());
        $this->assertSame('أحمد غير متاح الآن. ماذا تفضّل؟', last($this->deliveredTexts(Channel::WHATSAPP)));
    }

    public function test_a_session_left_on_the_transfer_step_never_follows_a_branch(): void
    {
        $this->supportFlow([$this->ahmed->id]);
        $this->customerWrites(Channel::WHATSAPP, 'القائمة');

        // إرسالٌ بعد التحويل تعذّر فبقيت الجلسة على الخطوة نفسها.
        FlowUserData::where('contact_id', $this->contact->id)->update(['current_step' => '5']);
        $before = count($this->deliveredTexts(Channel::WHATSAPP));

        $this->customerWrites(Channel::WHATSAPP, 'مرحبا');

        $this->assertCount($before, $this->deliveredTexts(Channel::WHATSAPP));
        $this->assertNull(FlowUserData::where('contact_id', $this->contact->id)->first());
    }

    public function test_the_not_available_path_can_transfer_to_a_second_department(): void
    {
        $this->makeFlow([
            $this->startNode('keywords', 'القائمة'),
            $this->buttonsNode('2', 'كيف نساعدك؟', ['الدعم الفني', 'المبيعات']),
            $this->actionNode('5', 'assign_agent', ['user_ids' => [$this->ahmed->id]]),
            $this->textNode('6', 'أحمد معك الآن'),
            $this->actionNode('8', 'assign_agent', ['user_ids' => [$this->fawaz->id]]),
            $this->textNode('9', 'أحمد غير متاح، فواز معك الآن'),
            $this->textNode('10', 'لا أحد متاح الآن'),
        ], [
            ['1', '2'], ['2', '5', 'a'],
            ['5', '6', 'available'], ['5', '8', 'unavailable'],
            ['8', '9', 'available'], ['8', '10', 'unavailable'],
        ], ['channels' => self::CHANNELS]);
        $this->online($this->fawaz);

        $this->pressSupport();

        $this->assertSame($this->fawaz->id, $this->assignee());
        $this->assertSame('أحمد غير متاح، فواز معك الآن', last($this->deliveredTexts(Channel::WHATSAPP)));
    }

    public function test_a_flow_without_a_transfer_still_follows_its_action_edges(): void
    {
        $group = \App\Models\ContactGroup::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'مهتمّون',
            'created_by' => $this->owner->id,
        ]);
        $this->makeFlow([
            $this->startNode('keywords', 'القائمة'),
            $this->buttonsNode('2', 'كيف نساعدك؟', ['الدعم الفني', 'المبيعات']),
            $this->actionNode('5', 'add_to_group', ['group_id' => $group->id]),
            $this->textNode('6', 'أضفناك إلى القائمة'),
        ], [['1', '2'], ['2', '5', 'a'], ['5', '6']], ['channels' => self::CHANNELS]);

        $this->pressSupport();

        $this->assertSame('أضفناك إلى القائمة', last($this->deliveredTexts(Channel::WHATSAPP)));
    }

    public function test_the_activity_log_names_automation_as_the_actor(): void
    {
        $plan = Subscription::where('organization_id', $this->organization->id)->first()->plan;
        $metadata = json_decode($plan->metadata, true);
        $plan->update(['metadata' => json_encode($metadata + ['activity_log' => true])]);
        \Illuminate\Support\Facades\Cache::flush();
        \App\Support\RequestLookupCache::forget('subscription:');

        $this->supportFlow([$this->ahmed->id]);
        $this->online($this->ahmed);

        $this->pressSupport();

        $log = ActivityLog::where('organization_id', $this->organization->id)
            ->where('event', \App\Services\ActivityLogger::TICKET_ASSIGNED)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(__('Automation'), $log->user_name);
        $this->assertSame('flow', $log->properties['via'] ?? null);
    }

    // ───────────────────── مساعدات ─────────────────────

    private function member(string $name): User
    {
        $user = User::factory()->create(['role' => 'user', 'first_name' => $name, 'last_name' => 'الموظف']);
        Team::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $this->organization->id,
            'role' => 'agent',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        return $user;
    }

    private function online(User $user): void
    {
        AgentPresence::touch($this->organization->id, $user->id, 'tab-' . $user->id, AgentPresence::WEB);
    }

    /** محادثات مفتوحة مسندة إليه من جهات أخرى. */
    private function busyWith(User $user, int $open): void
    {
        for ($i = 0; $i < $open; $i++) {
            $contact = Contact::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'organization_id' => $this->organization->id,
                'first_name' => 'عميل ' . $i,
                'phone' => '+9665' . random_int(10000000, 99999999),
                'created_by' => $this->owner->id,
            ]);
            ChatTicket::create([
                'contact_id' => $contact->id,
                'assigned_to' => $user->id,
                'status' => 'open',
                'is_latest' => true,
            ]);
        }
    }

    private function pressSupport(): void
    {
        $this->customerWrites(Channel::WHATSAPP, 'القائمة');
        $this->customerPresses(Channel::WHATSAPP, 'الدعم الفني');
    }

    private function assignee(): ?int
    {
        $assigned = $this->latestTicket($this->contact->fresh())?->assigned_to;

        return $assigned !== null ? (int) $assigned : null;
    }

    private function assignmentLines(Contact $contact): int
    {
        return ChatTicketLog::where('contact_id', $contact->id)
            ->where('description', 'like', 'Conversation was assigned to%')
            ->count();
    }

    /** @return array<int, string> عناوين الأزرار كما وصلت إلى المنصّة. */
    private function offeredButtons(string $channel): array
    {
        $sent = last($this->deliveredTo($channel));

        return match ($channel) {
            Channel::WHATSAPP => array_map(fn ($b) => $b['reply']['title'], $sent['interactive']['action']['buttons'] ?? []),
            Channel::TELEGRAM => array_map(fn ($row) => $row[0]['text'], (is_string($sent['reply_markup'] ?? null) ? json_decode($sent['reply_markup'], true) : $sent['reply_markup'] ?? [])['inline_keyboard'] ?? []),
            Channel::WEBCHAT => array_map(fn ($b) => $b['title'], $sent['interactive']['buttons'] ?? []),
        };
    }
}
