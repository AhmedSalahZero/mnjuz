<?php

namespace Tests\Feature\MobileApi;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * قائمة تذاكر الدعم من التطبيق.
 *
 * التذكرة هنا حالة المحادثة لا كيان مستقل: لكل جهة اتصال تذكرة «أحدث»
 * تحمل الحالة ومن أُسندت إليه.
 */
class MobileTicketTest extends MobileApiTestCase
{
    private function ticket(string $status = 'open', ?User $assignee = null, array $contact = []): int
    {
        $row = Contact::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'عميل',
            'phone' => '+9665' . random_int(10000000, 99999999),
            'created_by' => $this->owner->id,
        ], $contact));

        DB::table('chat_tickets')->insert([
            'contact_id' => $row->id,
            'assigned_to' => $assignee?->id,
            'status' => $status,
            'is_latest' => true,
            'assigned_seen' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $row->id;
    }

    // ------------------------------------------------- القائمة

    public function test_it_lists_tickets(): void
    {
        $this->ticket('open');
        $this->ticket('closed');

        $this->getJson('/api/v1/tickets')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_it_filters_by_status(): void
    {
        $this->ticket('open');
        $this->ticket('closed');

        $this->getJson('/api/v1/tickets?status=open')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.status', 'open');
    }

    public function test_it_filters_by_assignee(): void
    {
        $agent = $this->member('agent');
        $this->ticket('open', $agent);
        $this->ticket('open');

        $this->getJson('/api/v1/tickets?assigned_to=' . $agent->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.assigned_to', $agent->id);
    }

    /** «غير مُسندة» سؤال يتكرّر كثيراً، فله قيمة صريحة. */
    public function test_it_filters_unassigned(): void
    {
        $this->ticket('open', $this->member('agent'));
        $this->ticket('open');

        $this->getJson('/api/v1/tickets?assigned_to=unassigned')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.assigned_to', null);
    }

    public function test_it_searches_by_contact(): void
    {
        $this->ticket('open', null, ['first_name' => 'سارة']);
        $this->ticket('open', null, ['first_name' => 'خالد']);

        $this->getJson('/api/v1/tickets?search=سارة')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.contact_name', 'سارة');
    }

    /** تذاكر منشأة أخرى لا تظهر. */
    public function test_tickets_of_another_organization_are_hidden(): void
    {
        $stranger = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id + 999,
            'first_name' => 'غريب',
            'phone' => '+966500000077',
            'created_by' => 0,
        ]);

        DB::table('chat_tickets')->insert([
            'contact_id' => $stranger->id,
            'status' => 'open',
            'is_latest' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(0, 'data.items');
    }

    /** الموظّف لا يرى إلّا ما أُسند إليه — نفس ما يحكم قائمة المحادثات. */
    public function test_an_agent_sees_only_its_own_tickets(): void
    {
        $agent = $this->member('agent');
        $this->ticket('open', $agent);
        $this->ticket('open', $this->member('agent'));
        $this->ticket('open');

        $this->actAs($agent);

        $this->getJson('/api/v1/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.assigned_to', $agent->id);
    }

    public function test_the_manager_sees_every_ticket(): void
    {
        $this->ticket('open', $this->member('agent'));
        $this->ticket('open');

        $this->actAs($this->member('manager'));

        $this->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(2, 'data.items');
    }

    /** التذكرة تحمل اسم من أُسندت إليه كي لا يستعلم التطبيق عن كل واحد. */
    public function test_a_ticket_carries_the_assignee_name(): void
    {
        $agent = $this->member('agent');
        $agent->forceFill(['first_name' => 'خالد', 'last_name' => 'العتيبي'])->save();
        $this->ticket('open', $agent);

        $this->getJson('/api/v1/tickets')
            ->assertOk()
            ->assertJsonPath('data.items.0.assigned_to_name', 'خالد العتيبي');
    }

    // ------------------------------------------------- العدّادات

    public function test_it_summarises_counts(): void
    {
        $this->ticket('open');
        $this->ticket('open', $this->member('agent'));
        $this->ticket('closed');

        $this->getJson('/api/v1/tickets/summary')
            ->assertOk()
            ->assertJsonPath('data.open', 2)
            ->assertJsonPath('data.closed', 1)
            ->assertJsonPath('data.unassigned', 2);
    }

    /** وعدّاد الموظّف يخصّه وحده. */
    public function test_the_agent_summary_is_scoped_to_itself(): void
    {
        $agent = $this->member('agent');
        $this->ticket('open', $agent);
        $this->ticket('open');

        $this->actAs($agent);

        $this->getJson('/api/v1/tickets/summary')
            ->assertOk()
            ->assertJsonPath('data.open', 1);
    }

    public function test_it_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/tickets')->assertStatus(401);
    }
}
