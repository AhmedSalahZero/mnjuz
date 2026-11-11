<?php

namespace Tests\Feature\ConversationCycle;

use App\Models\Chat;
use App\Models\ChatTicketLog;
use App\Models\ConversationRating;
use App\Models\Team;
use App\Models\User;
use App\Services\Messaging\Channel;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * التذكرة على كل قناة: الإسناد عند أول رسالة، والإغلاق وآثاره، وعودتها
 * مفتوحة حين يكتب العميل — من الويب ومن التطبيق.
 */
class TicketCycleTest extends ConversationCycleTestCase
{
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

    /** @return array<int, int> أعضاء المنشأة النشطون بترتيب الدور. */
    private function rotation(): array
    {
        return Team::where('organization_id', $this->organization->id)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function test_round_robin_hands_each_new_conversation_to_the_next_member_whatever_the_channel(): void
    {
        $this->member();
        $this->member();
        $this->settings(['tickets' => ['assignment_mode' => 'round_robin', 'auto_assignment' => true]]);

        foreach (self::CHANNELS as $channel) {
            $this->customerWrites($channel, 'مرحبا');
        }

        $assigned = array_map(fn (string $channel) => (int) $this->latestTicket($this->customer($channel))->assigned_to, self::CHANNELS);
        $this->assertSame(array_slice($this->rotation(), 0, 3), $assigned);
    }

    public function test_auto_assignment_spreads_conversations_by_load_across_channels(): void
    {
        $this->member();
        $this->settings(['tickets' => ['assignment_mode' => 'auto', 'auto_assignment' => true]]);

        $this->customerWrites(Channel::TELEGRAM, 'مرحبا');
        $this->customerWrites(Channel::WEBCHAT, 'مرحبا');

        $first = (int) $this->latestTicket($this->customer(Channel::TELEGRAM))->assigned_to;
        $second = (int) $this->latestTicket($this->customer(Channel::WEBCHAT))->assigned_to;

        $this->assertContains($first, $this->rotation());
        $this->assertContains($second, $this->rotation());
        $this->assertNotSame($first, $second, 'the second conversation goes to the less busy member');
    }

    public function test_a_conversation_waits_unassigned_in_manual_mode(): void
    {
        foreach (self::CHANNELS as $channel) {
            $this->customerWrites($channel, 'مرحبا');
            $this->assertNull($this->latestTicket($this->customer($channel))->assigned_to, $channel);
        }
    }

    /** الإغلاق من الويب: الحالة، والقراءة، وسطر في سجلّ المحادثة. */
    #[DataProvider('channels')]
    public function test_closing_from_the_inbox_closes_marks_read_and_logs(string $channel): void
    {
        $this->customerWrites($channel, 'مرحبا');
        $this->customerWrites($channel, 'هل من أحد؟');
        $customer = $this->customer($channel);
        $this->assertSame(2, Chat::where('contact_id', $customer->id)->where('type', 'inbound')->where('is_read', 0)->count());

        $this->agentCloses($customer);

        $ticket = $this->latestTicket($customer);
        $this->assertSame('closed', $ticket->status);
        $this->assertSame($this->owner->id, (int) $ticket->assigned_to, 'whoever closes it owns it');
        $this->assertSame(0, Chat::where('contact_id', $customer->id)->where('type', 'inbound')->where('is_read', 0)->count());
        $this->assertTrue(ChatTicketLog::where('contact_id', $customer->id)->where('description', 'Conversation was moved from opened to closed')->exists());
    }

    /** والإغلاق من التطبيق يمرّ بالمسار نفسه: الاستبيان يصل، والتذكرة تعود برسالة. */
    #[DataProvider('channels')]
    public function test_closing_from_the_mobile_app_sends_the_survey_and_a_new_message_reopens(string $channel): void
    {
        $this->customerWrites($channel, 'مرحبا');
        $customer = $this->customer($channel);
        $ticketId = $this->latestTicket($customer)->id;

        $this->owner->forceFill(['current_mobile_organization_id' => $this->organization->id])->save();
        \App\Models\Addon::factory()->create(['name' => 'Mobile App', 'status' => 1, 'is_active' => 1]);
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/toggle-ticket-status/' . $customer->id, ['status' => 'closed'])->assertOk();

        $this->assertSame('closed', $this->latestTicket($customer)->status);
        $this->assertSame(1, ConversationRating::where('contact_id', $customer->id)->whereNotNull('sent_at')->count());

        $this->customerWrites($channel, 'رجعت');

        $this->assertSame($ticketId, $this->latestTicket($customer)->id);
        $this->assertSame('open', $this->latestTicket($customer)->status);
    }

    /** مع «أعد الإسناد عند العودة»: التذكرة العائدة تذهب لمن عليه الدور لا لمن أغلقها. */
    public function test_a_reopened_conversation_is_reassigned_when_the_merchant_asks_for_it(): void
    {
        $this->member();
        $this->settings(['tickets' => ['assignment_mode' => 'round_robin', 'auto_assignment' => true, 'reassign_reopened_chats' => true]]);

        $this->customerWrites(Channel::TELEGRAM, 'مرحبا');
        $customer = $this->customer(Channel::TELEGRAM);
        $this->agentCloses($customer);
        $this->assertSame($this->owner->id, (int) $this->latestTicket($customer)->assigned_to);

        $this->customerWrites(Channel::TELEGRAM, 'رجعت');

        $ticket = $this->latestTicket($customer);
        $this->assertSame('open', $ticket->status);
        $this->assertContains((int) $ticket->assigned_to, $this->rotation());
        $this->assertNotSame($this->owner->id, (int) $ticket->assigned_to, 'the turn moved on');
    }
}
