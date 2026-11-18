<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Support\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * نافذة «عرض جهات الاتصال» في صفحة المجموعة.
 *
 * الصفحة كانت تعرض الاسم والعدد فقط، فلا يعرف التاجر من في المجموعة.
 * المسار يُرجع جهاتها صفحة صفحة، ولا يتجاوز حدود المنشأة، ويُخفي الرقم
 * عن الموظف كما تفعل صفحة جهات الاتصال.
 */
class ContactGroupContactsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'user']);
        $this->organization = $this->organizationFor($this->user);

        // HandleInertiaRequests يقرأ هذه الإضافة وإعداد التخزين.
        Addon::factory()->create(['name' => 'Google Authenticator']);
        Setting::updateOrCreate(['key' => 'storage_system'], ['value' => 'local']);

        $this->actAs($this->user, $this->organization);
    }

    public function test_it_lists_only_the_contacts_of_the_group_newest_first(): void
    {
        $group = $this->group('أنثى');
        $other = $this->group('ذكر');

        $older = $this->contact('سارة', '+966500000001');
        $newer = $this->contact('نورة', '+966500000002');
        $outside = $this->contact('خالد', '+966500000003');
        $trashed = $this->contact('محذوفة', '+966500000004');

        $group->contacts()->attach($older->id);
        $group->contacts()->attach($newer->id);
        $group->contacts()->attach($trashed->id);
        $other->contacts()->attach($outside->id);
        $trashed->delete();

        $response = $this->getJson("/contact-groups/{$group->uuid}/contacts");

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonStructure(['data' => [['uuid', 'full_name', 'phone', 'email', 'opted_out', 'channel', 'channel_label']], 'current_page', 'last_page', 'per_page', 'total']);

        $this->assertSame([(string) $newer->uuid, (string) $older->uuid], array_column($response->json('data'), 'uuid'));
        $this->assertSame('نورة', $response->json('data.0.full_name'));
        $this->assertStringContainsString('50 000 0002', $response->json('data.0.phone'));
    }

    /** ما يُبعد الجهة عن الحملات ظاهرٌ في القائمة: الانسحاب، والقناة. */
    public function test_it_shows_why_a_contact_would_not_receive_a_campaign(): void
    {
        $group = $this->group('إعادة الاشتراك في تلقي العروض');
        $optedOut = $this->contact('منسحبة', '+966500000011');
        $optedOut->forceFill(['marketing_opted_out_at' => now()])->save();
        $visitor = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'زائر',
            'channel' => 'webchat',
            'created_by' => $this->user->id,
        ]);
        $group->contacts()->attach([$optedOut->id, $visitor->id]);

        $rows = collect($this->getJson("/contact-groups/{$group->uuid}/contacts")->assertOk()->json('data'))->keyBy('uuid');

        $this->assertTrue($rows[(string) $optedOut->uuid]['opted_out']);
        $this->assertSame('whatsapp', $rows[(string) $optedOut->uuid]['channel']);
        $this->assertFalse($rows[(string) $visitor->uuid]['opted_out']);
        $this->assertSame('webchat', $rows[(string) $visitor->uuid]['channel']);
        $this->assertSame('Web Chat', $rows[(string) $visitor->uuid]['channel_label']);
    }

    public function test_it_paginates_twenty_per_page(): void
    {
        $group = $this->group('كبيرة');
        foreach (range(1, 25) as $i) {
            $group->contacts()->attach($this->contact("عميل {$i}", '+9665000001' . str_pad((string) $i, 2, '0', STR_PAD_LEFT))->id);
        }

        $this->getJson("/contact-groups/{$group->uuid}/contacts")
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('total', 25)
            ->assertJsonPath('last_page', 2);

        $this->getJson("/contact-groups/{$group->uuid}/contacts?page=2")
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('data.4.full_name', 'عميل 1');
    }

    public function test_it_searches_by_name_and_phone_inside_the_group(): void
    {
        $group = $this->group('أنثى');
        $sara = $this->contact('سارة', '+966511111111');
        $noura = $this->contact('نورة', '+966522222222');
        $group->contacts()->attach([$sara->id, $noura->id]);
        // جهة بالاسم نفسه خارج المجموعة لا تظهر.
        $this->contact('سارة', '+966533333333');

        $byName = $this->getJson("/contact-groups/{$group->uuid}/contacts?search=" . urlencode('سارة'));
        $this->assertSame([(string) $sara->uuid], array_column($byName->json('data'), 'uuid'));

        $byPhone = $this->getJson("/contact-groups/{$group->uuid}/contacts?search=0522222222");
        $this->assertSame([(string) $noura->uuid], array_column($byPhone->json('data'), 'uuid'));
    }

    public function test_a_group_of_another_organization_is_not_found(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $otherOrganization = $this->organizationFor($owner);
        $foreign = ContactGroup::create(['organization_id' => $otherOrganization->id, 'name' => 'منشأة أخرى', 'created_by' => $owner->id]);
        $foreignContact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $otherOrganization->id,
            'first_name' => 'سري',
            'phone' => '+966544444444',
            'created_by' => $owner->id,
        ]);
        $foreign->contacts()->attach($foreignContact->id);

        $this->getJson("/contact-groups/{$foreign->uuid}/contacts")->assertNotFound();
        $this->getJson('/contact-groups/' . Str::uuid() . '/contacts')->assertNotFound();
    }

    public function test_invalid_input_is_rejected(): void
    {
        $group = $this->group('أنثى');

        $this->getJson("/contact-groups/{$group->uuid}/contacts?page=0")->assertStatus(422);
        $this->getJson("/contact-groups/{$group->uuid}/contacts?search=" . str_repeat('a', 101))->assertStatus(422);
    }

    public function test_a_guest_cannot_list_the_contacts(): void
    {
        $group = $this->group('أنثى');
        auth()->logout();

        $this->get("/contact-groups/{$group->uuid}/contacts")->assertRedirect();
        $this->getJson("/contact-groups/{$group->uuid}/contacts")->assertUnauthorized();
    }

    public function test_a_masked_agent_does_not_see_the_full_number(): void
    {
        $group = $this->group('أنثى');
        $group->contacts()->attach($this->contact('سارة', '+966501234567')->id);
        $this->ticketSettings(masked: true);

        $agent = User::factory()->create(['role' => 'user']);
        Team::create([
            'user_id' => $agent->id,
            'organization_id' => $this->organization->id,
            'role' => OrganizationRole::AGENT,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $this->actAs($agent, $this->organization);

        $phone = $this->getJson("/contact-groups/{$group->uuid}/contacts")->assertOk()->json('data.0.phone');

        $this->assertStringNotContainsString('1234567', str_replace(' ', '', $phone));
        $this->assertStringContainsString('*', $phone);
    }

    public function test_the_owner_sees_the_full_number_even_when_masking_is_on(): void
    {
        $group = $this->group('أنثى');
        $group->contacts()->attach($this->contact('سارة', '+966501234567')->id);
        $this->ticketSettings(masked: true);

        $phone = $this->getJson("/contact-groups/{$group->uuid}/contacts")->assertOk()->json('data.0.phone');

        $this->assertSame('+966501234567', str_replace(' ', '', $phone));
    }

    // ---------------------------------------------- صفحة المجموعة (getRow)

    public function test_the_group_page_shows_the_group_and_its_count(): void
    {
        $group = $this->group('أنثى');
        $group->contacts()->attach([$this->contact('سارة', '+966500000001')->id, $this->contact('نورة', '+966500000002')->id]);

        $props = $this->inertiaProps('/contact-groups?id=' . $group->uuid);

        $this->assertSame((string) $group->uuid, $props['group']['uuid']);
        $this->assertSame(2, $props['group']['contact_count']);
    }

    public function test_the_group_page_does_not_open_a_group_of_another_organization(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $otherOrganization = $this->organizationFor($owner);
        $foreign = ContactGroup::create(['organization_id' => $otherOrganization->id, 'name' => 'منشأة أخرى', 'created_by' => $owner->id]);

        $props = $this->inertiaProps('/contact-groups?id=' . $foreign->uuid);

        $this->assertNull($props['group']);
    }

    // ------------------------------------------------- أدوات

    private function organizationFor(User $owner): Organization
    {
        $organization = Organization::factory()->create(['created_by' => $owner->id]);
        Team::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $organization->id,
            'role' => 'owner',
            'created_by' => $owner->id,
        ]);

        // مسارات جهات الاتصال خلف check.subscription.
        $plan = SubscriptionPlan::create([
            'name' => 'Test Plan',
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(['contacts_limit' => -1]),
        ]);
        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        return $organization;
    }

    private function actAs(User $user, Organization $organization): void
    {
        $this->actingAs($user);
        session(['current_organization' => $organization->id]);
    }

    private function ticketSettings(bool $masked): void
    {
        $metadata = json_decode($this->organization->fresh()->metadata, true) ?: [];
        $metadata['tickets'] = array_merge($metadata['tickets'] ?? [], [
            'active' => true,
            'encrypt_contacts_for_agents' => $masked,
        ]);
        $this->organization->update(['metadata' => json_encode($metadata)]);
    }

    private function group(string $name): ContactGroup
    {
        return ContactGroup::create([
            'organization_id' => $this->organization->id,
            'name' => $name,
            'created_by' => $this->user->id,
        ]);
    }

    private function contact(string $firstName, string $phone): Contact
    {
        return Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => $firstName,
            'phone' => $phone,
            'created_by' => $this->user->id,
        ]);
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
