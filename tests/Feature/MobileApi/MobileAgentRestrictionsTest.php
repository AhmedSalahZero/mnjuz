<?php

namespace Tests\Feature\MobileApi;

use App\Events\NewChatEvent;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WebChatVisitor;
use App\Services\Messaging\Channel;
use App\Services\WebChat\WebChatWidgetService;
use App\Support\OrganizationRole;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

/**
 * قيود الموظّف في التطبيق — config('chat.mobile_agent_restrictions').
 *
 * معطّلة (الافتراضي): التطبيق كما كان حرفياً — الأرقام كاملة، والمزامنة
 * تُرجع كل المحادثات. مفعّلة: ما يُطبَّق في الويب — إخفاء الأرقام حين تُفعّله
 * المنشأة، والمزامنة لما أُسند إلى الموظّف حين تمنع رؤية الكل.
 */
class MobileAgentRestrictionsTest extends MobileChannelsTestCase
{
    private const PHONE = '+966500000123';

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([NewChatEvent::class]);
    }

    protected function planMetadata(): array
    {
        $metadata = parent::planMetadata();
        $metadata['addons']['Web Chat'] = true;

        return $metadata;
    }

    // ------------------------------------------------- معطّلة: لا تغيير

    public function test_without_the_switch_a_masked_agent_still_sees_full_numbers(): void
    {
        $this->ticketSettings(['encrypt_contacts_for_agents' => true]);
        $contact = $this->whatsappContact(['phone' => self::PHONE]);
        $this->message($contact);
        // /tickets يقصر الموظّف على ما أُسند إليه دائماً.
        $agent = $this->agent();
        $this->ticket($contact, ['assigned_to' => $agent->id]);
        $this->actAs($agent);

        $this->assertSame(self::PHONE, $this->getJson('/api/v1/contacts')->assertOk()->json('data.0.phone'));
        $this->assertSame(self::PHONE, $this->getJson('/api/v1/contacts/' . $contact->uuid)->assertOk()->json('data.phone'));
        $this->assertSame(self::PHONE, $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2')->assertOk()->json('data.0.contact.phone'));
        $this->assertSame(self::PHONE, $this->getJson('/api/v1/list-messages-from-uuid-to-end')->assertOk()->json('data.0.messages.0.value.phone'));
        $this->assertSame(self::PHONE, $this->getJson('/api/v1/tickets')->assertOk()->json('data.items.0.phone'));
    }

    public function test_without_the_switch_the_agent_sync_is_not_restricted(): void
    {
        $this->ticketSettings(['allow_agents_to_view_all_chats' => false]);
        [$mine, $others] = $this->twoConversations($agent = $this->agent());
        $this->actAs($agent);

        $response = $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertFalse($response->json('assigned_only'));
    }

    // ------------------------------------------------- مفعّلة: الإخفاء

    public function test_a_masked_agent_sees_masked_numbers_everywhere(): void
    {
        $this->restrict();
        $this->ticketSettings(['encrypt_contacts_for_agents' => true]);
        $contact = $this->whatsappContact(['phone' => self::PHONE]);
        $this->message($contact);
        // /tickets يقصر الموظّف على ما أُسند إليه دائماً.
        $agent = $this->agent();
        $this->ticket($contact, ['assigned_to' => $agent->id]);
        $this->actAs($agent);

        $listed = $this->getJson('/api/v1/contacts')->assertOk()->json('data.0');
        $this->assertMasked($listed['phone']);
        $this->assertMasked($listed['formatted_phone_number']);

        $detail = $this->getJson('/api/v1/contacts/' . $contact->uuid)->assertOk()->json('data');
        $this->assertMasked($detail['phone']);
        $this->assertMasked($detail['formatted_phone_number']);

        $v2 = $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2')->assertOk()->json('data.0.contact');
        $this->assertMasked($v2['phone']);
        $this->assertMasked($v2['formatted_phone_number']);

        $v1 = $this->getJson('/api/v1/list-messages-from-uuid-to-end')->assertOk()->json('data.0.messages.0.value');
        $this->assertMasked($v1['phone']);
        $this->assertMasked($v1['formatted_phone_number']);

        $ticket = $this->getJson('/api/v1/tickets')->assertOk()->json('data.items.0');
        $this->assertMasked($ticket['phone']);
        $this->assertMasked($ticket['formatted_phone_number']);
    }

    public function test_the_visitor_card_phone_is_masked_too(): void
    {
        $this->restrict();
        $this->ticketSettings(['encrypt_contacts_for_agents' => true]);
        $visitor = $this->visitorContact();
        $this->actAs($this->agent());

        $listed = collect($this->getJson('/api/v1/contacts')->assertOk()->json('data'))->firstWhere('uuid', (string) $visitor->uuid);
        $this->assertMasked($listed['webchat_visitor']['phone']);
        $this->assertMasked($listed['formatted_phone_number']);

        $detail = $this->getJson('/api/v1/contacts/' . $visitor->uuid)->assertOk()->json('data');
        $this->assertMasked($detail['webchat_visitor']['phone']);
    }

    public function test_an_agent_cannot_unmask_by_naming_an_organization_they_own(): void
    {
        $this->restrict();
        $this->ticketSettings(['encrypt_contacts_for_agents' => true]);
        $this->whatsappContact(['phone' => self::PHONE]);
        $agent = $this->agent();

        $ownOrganization = Organization::factory()->create(['created_by' => $agent->id]);
        Team::factory()->create([
            'user_id' => $agent->id,
            'organization_id' => $ownOrganization->id,
            'role' => OrganizationRole::OWNER,
            'created_by' => $agent->id,
        ]);
        $this->actAs($agent);

        $this->assertMasked($this->getJson('/api/v1/contacts?organization_id=' . $ownOrganization->id)->assertOk()->json('data.0.phone'));
    }

    public function test_the_owner_and_an_unmasked_agent_see_full_numbers(): void
    {
        $this->restrict();
        $this->ticketSettings(['encrypt_contacts_for_agents' => true]);
        $this->whatsappContact(['phone' => self::PHONE]);

        $this->assertSame(self::PHONE, $this->getJson('/api/v1/contacts')->assertOk()->json('data.0.phone'));
    }

    public function test_an_agent_without_masking_sees_full_numbers(): void
    {
        $this->restrict();
        $this->ticketSettings(['encrypt_contacts_for_agents' => false]);
        $this->whatsappContact(['phone' => self::PHONE]);
        $this->actAs($this->agent());

        $this->assertSame(self::PHONE, $this->getJson('/api/v1/contacts')->assertOk()->json('data.0.phone'));
    }

    public function test_a_masked_agent_sends_by_contact_uuid_and_gets_no_full_number_back(): void
    {
        Bus::fake();
        $this->restrict();
        $this->connectWhatsapp();
        $this->ticketSettings(['encrypt_contacts_for_agents' => true]);
        $contact = $this->whatsappContact(['phone' => self::PHONE]);
        $this->message($contact, ['created_at' => now()->subMinutes(5)]);
        Contact::where('id', $contact->id)->update(['last_inbound_chat_created_at' => now()->subMinutes(5)]);
        $this->actAs($this->agent());

        $response = $this->postJson('/api/v1/send-msg', [
            'type' => 'text',
            'contact_uuid' => $contact->uuid,
            'message' => 'أهلاً',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertMasked($response->json('data.phone'));
    }

    // ------------------------------------------------- مفعّلة: قصر المزامنة

    public function test_a_restricted_agent_syncs_only_assigned_conversations(): void
    {
        $this->restrict();
        $this->ticketSettings(['allow_agents_to_view_all_chats' => false]);
        $agent = $this->agent();
        [$mine] = $this->twoConversations($agent);
        $this->actAs($agent);

        foreach (['/api/v1/list-messages-from-uuid-to-end-v2', '/api/v1/list-messages-from-uuid-to-end'] as $url) {
            $response = $this->getJson($url)->assertOk();
            $this->assertCount(1, $response->json('data'), $url);
            $this->assertTrue($response->json('assigned_only'), $url);
        }

        $this->assertSame((string) $mine->uuid, $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2')->json('data.0.contact.contact_uuid'));
    }

    public function test_a_conversation_outside_the_agent_scope_cannot_be_backfilled(): void
    {
        $this->restrict();
        $this->ticketSettings(['allow_agents_to_view_all_chats' => false]);
        $agent = $this->agent();
        [, $others] = $this->twoConversations($agent);
        $this->actAs($agent);

        $this->assertSame([], $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2?contact_uuid=' . $others->uuid)->assertOk()->json('data'));
    }

    public function test_agents_see_everything_when_the_organization_allows_it(): void
    {
        $this->restrict();
        $this->ticketSettings(['allow_agents_to_view_all_chats' => true]);
        $agent = $this->agent();
        $this->twoConversations($agent);
        $this->actAs($agent);

        $response = $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2')->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertFalse($response->json('assigned_only'));
    }

    public function test_agents_see_everything_when_tickets_are_off(): void
    {
        $this->restrict();
        $this->ticketSettings(['active' => false, 'allow_agents_to_view_all_chats' => false]);
        $agent = $this->agent();
        $this->twoConversations($agent);
        $this->actAs($agent);

        $this->assertCount(2, $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2')->assertOk()->json('data'));
    }

    public function test_the_owner_sync_is_never_restricted(): void
    {
        $this->restrict();
        $this->ticketSettings(['allow_agents_to_view_all_chats' => false]);
        $this->twoConversations($this->agent());

        $response = $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2')->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertFalse($response->json('assigned_only'));
    }

    // ------------------------------------------------------------- أدوات

    private function restrict(): void
    {
        config(['chat.mobile_agent_restrictions' => true]);
    }

    private function assertMasked(?string $value): void
    {
        $this->assertNotNull($value);
        $this->assertStringContainsString('*', $value, "الرقم يجب أن يصل مخفيّاً: {$value}");
        $this->assertStringNotContainsString('0123', $value);
    }

    /** @return array{0: Contact, 1: Contact} محادثة مسندة إلى الموظّف، وأخرى لغيره. */
    private function twoConversations(User $agent): array
    {
        $mine = $this->whatsappContact();
        $this->message($mine);
        $this->ticket($mine, ['assigned_to' => $agent->id]);

        $others = $this->whatsappContact();
        $this->message($others);
        $this->ticket($others, ['assigned_to' => $this->owner->id]);

        return [$mine, $others];
    }

    private function visitorContact(): Contact
    {
        $widget = app(WebChatWidgetService::class)->ensureForOrganization($this->organization->id, 'متجر');
        $visitor = WebChatVisitor::create([
            'organization_id' => $this->organization->id,
            'channel_account_id' => $widget->id,
            'token_hash' => WebChatVisitor::hashToken(WebChatVisitor::newToken()),
            'name' => 'سارة',
            'phone' => '+966500000123',
        ]);

        return Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::WEBCHAT,
            'channel_identifier' => $visitor->uuid,
            'channel_account_id' => $widget->id,
            'first_name' => 'سارة',
            'formatted_phone' => '+966 50 000 0123',
            'created_by' => 0,
        ])->fresh();
    }
}
