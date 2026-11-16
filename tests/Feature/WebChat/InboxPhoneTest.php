<?php

namespace Tests\Feature\WebChat;

use App\Http\Resources\ContactResource;
use App\Models\Addon;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Support\OrganizationRole;
use Illuminate\Support\Str;

/**
 * جوال زائر المحادثة المباشرة في صندوق الوارد، وإخفاؤه عمّن لا يحقّ له.
 *
 * العطل: الرقم محفوظ (formatted_phone) ويظهر في صفحة جهات الاتصال، لكن
 * صندوق الوارد ينتقي أعمدة جهة الاتصال صراحةً بلا formatted_phone، فيصل
 * فارغاً — والتاجر يرى البريد ولا يرى الجوال.
 *
 * وإصلاحه وحده كان سيكشف الأرقام: إخفاء الأرقام عن الموظفين كان يُخفي
 * phone ويُعيد formatted_phone كاملاً — فأرقام عملاء واتساب كانت ظاهرة
 * أصلاً في صفحة جهات الاتصال والتصدير لمن يُفترض ألّا يراها.
 */
class InboxPhoneTest extends WebChatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // صفحات Inertia الكاملة تقرأ هذه الإضافة وإعداد التخزين.
        Setting::updateOrCreate(['key' => 'storage_system'], ['value' => 'local']);
        Addon::factory()->create(['name' => 'Google Authenticator']);
    }

    public function test_the_inbox_shows_the_visitor_phone(): void
    {
        $contact = $this->visitorContact();
        $this->ticketSettings(masked: true);
        $this->actAsOwner();

        $props = $this->inertiaProps('/chats/' . $contact->uuid);

        $this->assertSame('+966 50 123 4567', $props['contact']['formatted_phone_number']);
        $this->assertSame('+966501234567', $props['contact']['webchat_visitor']['phone']);
    }

    public function test_an_agent_sees_the_number_when_masking_is_off(): void
    {
        $contact = $this->visitorContact();
        $this->actAsAgent(masked: false);

        $props = $this->inertiaProps('/chats/' . $contact->uuid);

        $this->assertSame('+966 50 123 4567', $props['contact']['formatted_phone_number']);
    }

    public function test_a_masked_agent_sees_neither_copy_of_the_visitor_phone(): void
    {
        $contact = $this->visitorContact();
        $this->actAsAgent(masked: true);

        $props = $this->inertiaProps('/chats/' . $contact->uuid);

        $this->assertSame('+966 ** *** ****', $props['contact']['formatted_phone_number']);
        $this->assertSame('+966*********', $props['contact']['webchat_visitor']['phone']);
        $this->assertStringNotContainsString('1234567', json_encode($props['contact']));
    }

    /** رقم واتساب في صفحة جهة الاتصال: كان formatted_phone يُرسَل كاملاً. */
    public function test_a_whatsapp_number_is_masked_on_the_contact_page(): void
    {
        $contact = $this->whatsappContact();
        $this->actAsAgent(masked: true);

        $props = $this->inertiaProps('/contacts/' . $contact->uuid);

        $this->assertStringNotContainsString('1112233', json_encode($props['contact']));
        $this->assertSame('+966 ** *** ****', $props['contact']['formatted_phone_number']);
    }

    /** ومورد جهة الاتصال (التطبيق والجداول) كذلك. */
    public function test_a_whatsapp_number_is_masked_in_the_contact_resource(): void
    {
        $contact = $this->whatsappContact();
        $this->actAsAgent(masked: true);

        $payload = (new ContactResource($contact->fresh()))->toArray(request());

        $this->assertStringNotContainsString('1112233', (string) $payload['formatted_phone_number']);
        $this->assertStringNotContainsString('1112233', (string) $payload['phone']);
    }

    /** والمالك يرى الرقم كاملاً في المكانين، كما كان. */
    public function test_the_owner_still_sees_whatsapp_numbers_in_full(): void
    {
        $contact = $this->whatsappContact();
        $this->ticketSettings(masked: true);
        $this->actAsOwner();

        $props = $this->inertiaProps('/contacts/' . $contact->uuid);

        $this->assertSame('+966 50 111 2233', $props['contact']['formatted_phone_number']);
    }

    // ------------------------------------------------- أدوات

    private function visitorContact(): Contact
    {
        [$token, $visitor] = $this->openSession();
        $this->send($token, ['text' => 'مرحبا'])->assertStatus(202);

        return Contact::find($visitor->fresh()->contact_id);
    }

    private function whatsappContact(): Contact
    {
        return Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'عميل',
            'phone' => '+966501112233',
            'created_by' => 0,
        ]);
    }

    /** إعداد التذاكر كاملاً كما تحفظه صفحته (الصندوق يقرأ كل مفاتيحه). */
    private function ticketSettings(bool $masked): void
    {
        $metadata = json_decode($this->organization->fresh()->metadata, true) ?: [];
        $metadata['tickets'] = array_merge($metadata['tickets'] ?? [], [
            'active' => true,
            'auto_assignment' => false,
            'reassign_reopened_chats' => false,
            'allow_agents_to_view_all_chats' => true,
            'encrypt_contacts_for_agents' => $masked,
        ]);
        $this->organization->update(['metadata' => json_encode($metadata)]);
    }

    private function actAsAgent(bool $masked): User
    {
        $this->ticketSettings($masked);

        $agent = User::factory()->create(['role' => 'user']);
        Team::create([
            'user_id' => $agent->id,
            'organization_id' => $this->organization->id,
            'role' => OrganizationRole::AGENT,
            'status' => 'active',
            'created_by' => $this->organization->created_by,
        ]);

        $this->actingAs($agent);
        session(['current_organization' => $this->organization->id]);

        return $agent;
    }

    /** @return array<string, mixed> */
    private function inertiaProps(string $url): array
    {
        $response = $this->get($url);
        $response->assertOk();

        preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'no Inertia payload on ' . $url);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props'];
    }
}
