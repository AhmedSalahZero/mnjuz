<?php

namespace Tests\Feature\ConversationCycle;

use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Feature\Cycle\CycleTestCase;

/**
 * من يقرأ محادثة، وماذا يصل إلى متصفّحه.
 *
 * كانت «تحميل رسائل أقدم» تقبل أي رقم جهة اتصال بلا قيد منشأة ولا صلاحية،
 * وكان مرسل كل رسالة يُسلسَل كاملاً — ومعه سرّ المصادقة الثنائية — لكل موظف.
 * وكان GET /chat/send يرسل رسالة بطلبٍ لا يمرّ على حماية CSRF.
 */
class ConversationAccessTest extends CycleTestCase
{
    private function asOwner(): void
    {
        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);
    }

    /** رسالة مسجّلة كما يسجّلها التطبيق: صفّ chats وصفّ chat_logs. */
    private function message(Contact $contact, array $attributes = []): Chat
    {
        $chat = $this->chat($contact, $attributes);
        ChatLog::insert([
            'contact_id' => $contact->id,
            'entity_type' => 'chat',
            'entity_id' => $chat->id,
            'created_at' => now(),
        ]);

        return $chat;
    }

    private function otherOrganizationContact(): Contact
    {
        $stranger = User::factory()->create(['role' => 'user']);
        $other = Organization::factory()->create(['created_by' => $stranger->id, 'metadata' => json_encode([])]);

        $contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $other->id,
            'first_name' => 'عميل منشأة أخرى',
            'phone' => '+966500000999',
            'created_by' => $stranger->id,
        ]);
        Chat::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $other->id,
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'status' => 'delivered',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'سرّ تجاري']]),
            'created_at' => now(),
        ]);

        return $contact;
    }

    private function enableTickets(bool $agentsSeeAll): void
    {
        $metadata = json_decode($this->organization->metadata, true);
        $metadata['tickets'] = ['active' => true, 'allow_agents_to_view_all_chats' => $agentsSeeAll, 'auto_assignment' => false];
        $this->organization->update(['metadata' => json_encode($metadata)]);
    }

    private function agent(): User
    {
        $agent = User::factory()->create(['role' => 'user']);
        Team::factory()->create(['user_id' => $agent->id, 'organization_id' => $this->organization->id, 'role' => 'agent', 'created_by' => $this->owner->id]);

        return $agent;
    }

    // ------------------------------------------------- تحميل الرسائل الأقدم

    public function test_older_messages_load_for_a_conversation_of_the_current_organization(): void
    {
        $contact = $this->contact();
        $this->message($contact, ['type' => 'inbound', 'status' => 'delivered', 'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'أهلاً']])]);
        $this->asOwner();

        $response = $this->getJson("/chats/{$contact->id}/messages?page=1")->assertOk();

        $this->assertCount(1, $response->json('messages'));
        $this->assertFalse($response->json('hasMoreMessages'));
    }

    public function test_messages_of_another_organizations_contact_are_not_readable(): void
    {
        $foreign = $this->otherOrganizationContact();
        $this->asOwner();

        $this->getJson("/chats/{$foreign->id}/messages?page=1")
            ->assertNotFound()
            ->assertJsonMissing(['body' => 'سرّ تجاري']);
    }

    /** وكيلٌ لا يرى إلا ما أُسند إليه حين يمنع التاجر رؤية الكل — للصفحات الأقدم أيضاً. */
    public function test_an_agent_cannot_page_a_conversation_assigned_to_someone_else(): void
    {
        $this->enableTickets(agentsSeeAll: false);
        $agent = $this->agent();
        $contact = $this->contact();
        $this->message($contact, ['type' => 'inbound', 'status' => 'delivered']);
        $ticket = ChatTicket::create(['contact_id' => $contact->id, 'assigned_to' => $this->owner->id, 'status' => 'open', 'is_latest' => true]);

        $this->actingAs($agent);
        session(['current_organization' => $this->organization->id]);
        $this->getJson("/chats/{$contact->id}/messages?page=1")->assertNotFound();

        $ticket->update(['assigned_to' => $agent->id]);
        $this->getJson("/chats/{$contact->id}/messages?page=1")->assertOk();
    }

    // ------------------------------------------------- ما يُسلسَل للمتصفّح

    public function test_the_thread_carries_the_senders_name_but_none_of_their_secrets(): void
    {
        $this->owner->forceFill(['tfa_secret' => 'JBSWY3DPEHPK3PXP', 'phone' => '+966511111111'])->save();
        $contact = $this->contact();
        $this->message($contact, ['user_id' => $this->owner->id]);
        $this->asOwner();

        $page = $this->get('/chats/' . $contact->uuid)->assertOk()->viewData('page')['props'];
        $older = $this->getJson("/chats/{$contact->id}/messages?page=1")->assertOk()->json();

        foreach (['page' => $page['chatThread'], 'older' => $older['messages']] as $where => $thread) {
            $sender = $thread[0][0]['value']['user'];
            $this->assertSame(trim($this->owner->first_name . ' ' . $this->owner->last_name), $sender['full_name'], $where);

            $json = json_encode($thread);
            $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $json, "$where: tfa_secret leaked");
            $this->assertStringNotContainsString($this->owner->email, $json, "$where: sender email leaked");
            $this->assertStringNotContainsString('+966511111111', $json, "$where: sender phone leaked");
        }
    }

    public function test_a_user_never_serializes_the_two_factor_secret(): void
    {
        $this->owner->forceFill(['tfa_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        $this->assertArrayNotHasKey('tfa_secret', $this->owner->fresh()->toArray());
        // والقراءة المباشرة باقية لمن يتحقّق من الرمز.
        $this->assertSame('JBSWY3DPEHPK3PXP', $this->owner->fresh()->tfa_secret);
    }

    // ------------------------------------------------- الإرسال

    public function test_a_message_cannot_be_sent_with_a_get_request(): void
    {
        Bus::fake();
        $contact = $this->contact();
        $this->asOwner();

        $this->get('/chat/send?uuid=' . $contact->uuid . '&message=hi&type=text')->assertNotFound();

        $this->assertSame(0, Chat::where('contact_id', $contact->id)->count());
        Bus::assertNothingDispatched();
    }
}
