<?php

namespace Tests\Feature\MobileApi;

use App\Models\Chat;
use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * نقاط التذاكر في التطبيق تأخذ رقم جهة الاتصال من الطلب.
 *
 * كانت تبحث عنه في المنصّة كلها: مستخدمٌ في منشأة يُغلق محادثة منشأة أخرى
 * (فيصل لعميلها استبيان التقييم باسمها)، أو يُسندها لأي مستخدم، أو يعلّم
 * رسائلها مقروءة. الآن: منشأة المستخدم الحالية على التطبيق وحدها.
 */
class MobileTicketScopeTest extends MobileApiTestCase
{
    private function contactIn(Organization $organization, string $phone = '+966500000001'): Contact
    {
        $contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'first_name' => 'عميل',
            'phone' => $phone,
            'created_by' => $this->owner->id,
        ]);
        ChatTicket::create(['contact_id' => $contact->id, 'assigned_to' => null, 'status' => 'open', 'is_latest' => true]);

        return $contact;
    }

    private function otherOrganization(): Organization
    {
        $stranger = User::factory()->create(['role' => 'user']);

        return Organization::factory()->create(['created_by' => $stranger->id, 'metadata' => json_encode([])]);
    }

    private function unread(Contact $contact): Chat
    {
        return Chat::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $contact->organization_id,
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'status' => 'delivered',
            'is_read' => 0,
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'مرحباً']]),
            'created_at' => now(),
        ]);
    }

    private function ticketOf(Contact $contact): ChatTicket
    {
        return ChatTicket::where('contact_id', $contact->id)->where('is_latest', true)->firstOrFail();
    }

    // ------------------------------------------------- الإغلاق

    public function test_closing_a_conversation_of_the_current_organization_works(): void
    {
        $contact = $this->contactIn($this->organization);

        $this->postJson("/api/v1/toggle-ticket-status/{$contact->id}", ['status' => 'closed'])->assertOk();

        $this->assertSame('closed', $this->ticketOf($contact)->status);
    }

    public function test_a_conversation_of_another_organization_cannot_be_closed(): void
    {
        $foreign = $this->contactIn($this->otherOrganization(), '+966500000002');

        $this->postJson("/api/v1/toggle-ticket-status/{$foreign->id}", ['status' => 'closed'])->assertNotFound();

        $this->assertSame('open', $this->ticketOf($foreign)->status);
        $this->assertDatabaseMissing('conversation_ratings', ['contact_id' => $foreign->id]);
    }

    public function test_the_status_must_be_open_or_closed(): void
    {
        $contact = $this->contactIn($this->organization);

        $this->postJson("/api/v1/toggle-ticket-status/{$contact->id}", ['status' => 'deleted'])->assertStatus(400);
        $this->postJson("/api/v1/toggle-ticket-status/{$contact->id}", [])->assertStatus(400);

        $this->assertSame('open', $this->ticketOf($contact)->status);
    }

    // ------------------------------------------------- الإسناد

    public function test_a_conversation_is_assigned_to_a_member_of_the_organization(): void
    {
        $contact = $this->contactIn($this->organization);
        $agent = $this->member('agent');

        $this->postJson('/api/v1/assign-ticket', ['contact_id' => $contact->id, 'user_id' => $agent->id])->assertOk();

        $this->assertSame($agent->id, (int) $this->ticketOf($contact)->assigned_to);
    }

    public function test_a_conversation_of_another_organization_cannot_be_assigned(): void
    {
        $foreign = $this->contactIn($this->otherOrganization(), '+966500000003');

        $this->postJson('/api/v1/assign-ticket', ['contact_id' => $foreign->id, 'user_id' => $this->owner->id])->assertNotFound();

        $this->assertNull($this->ticketOf($foreign)->assigned_to);
    }

    public function test_a_conversation_cannot_be_assigned_to_someone_outside_the_organization(): void
    {
        $contact = $this->contactIn($this->organization);
        $outsider = User::factory()->create(['role' => 'user']);
        Team::factory()->create(['user_id' => $outsider->id, 'organization_id' => $this->otherOrganization()->id, 'role' => 'owner', 'created_by' => $outsider->id]);

        $this->postJson('/api/v1/assign-ticket', ['contact_id' => $contact->id, 'user_id' => $outsider->id])->assertNotFound();

        $this->assertNull($this->ticketOf($contact)->assigned_to);
    }

    // ------------------------------------------------- مقروء

    public function test_marking_read_is_limited_to_the_current_organization(): void
    {
        $own = $this->contactIn($this->organization);
        $foreign = $this->contactIn($this->otherOrganization(), '+966500000004');
        $ownChat = $this->unread($own);
        $foreignChat = $this->unread($foreign);

        $this->postJson('/api/v1/mark-as-read', ['contact_id' => $foreign->id])->assertNotFound();
        $this->postJson('/api/v1/mark-as-read', ['contact_id' => $own->id])->assertOk();

        $this->assertSame(0, (int) $foreignChat->fresh()->is_read);
        $this->assertSame(1, (int) $ownChat->fresh()->is_read);
    }
}
