<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * حذف مجموعات «Men <رقم>» التي رُفعت بالخطأ لمنشأة «مجمع تجديد الصحة والجمال الطبي».
 *
 * المطلوب: تختفي هذه المجموعات نهائياً، وتبقى جهات الاتصال كلها — ومن كان
 * فيها يبقى بلا مجموعة. ولا يُلمس أي اسم آخر، ولا أي منشأة أخرى.
 */
class DeleteMistakenMenGroupsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_18_110000_delete_mistaken_men_groups_for_organization.php';
    private const ORGANIZATION_UUID = 'fc83d605-9b9b-4725-a703-701abbf4ea96';

    private User $user;
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'user']);
        $this->organization = $this->organizationWithUuid(self::ORGANIZATION_UUID);
    }

    public function test_it_deletes_the_men_groups_and_keeps_every_contact(): void
    {
        $men = $this->group('Men 4521');
        $alreadyTrashed = $this->group('Men 2026');
        $alreadyTrashed->delete();
        $arabic = $this->group('ذكر');

        $first = $this->contact('عميل أول', '+966500000001');
        $second = $this->contact('عميل ثان', '+966500000002');
        $men->contacts()->attach([$first->id, $second->id]);
        $arabic->contacts()->attach([$first->id]);

        $this->runMigration();

        $this->assertNull(ContactGroup::withTrashed()->find($men->id), 'Men 4521 still exists');
        $this->assertNull(ContactGroup::withTrashed()->find($alreadyTrashed->id), 'the soft-deleted Men 2026 row still exists');
        $this->assertSame(0, DB::table('contact_contact_group')->whereIn('contact_group_id', [$men->id, $alreadyTrashed->id])->count());

        // جهات الاتصال باقية، غير محذوفة ولو حذفاً ناعماً.
        $this->assertNotNull(Contact::find($first->id));
        $this->assertNotNull(Contact::find($second->id));
        $this->assertSame(2, Contact::where('organization_id', $this->organization->id)->count());

        // ومن كان في مجموعة أخرى يبقى فيها.
        $this->assertSame([$arabic->id], $first->fresh()->contactGroups()->pluck('contact_groups.id')->all());
        $this->assertSame([], $second->fresh()->contactGroups()->pluck('contact_groups.id')->all());
    }

    public function test_it_only_matches_the_exact_men_number_pattern(): void
    {
        $kept = collect(['men 12', 'Men', 'Menu offers', 'Men 12 VIP', 'MEN 7', 'Women 5', 'ذكر'])
            ->map(fn (string $name) => $this->group($name));
        $deleted = $this->group('Men 12');

        $this->runMigration();

        $this->assertNull(ContactGroup::withTrashed()->find($deleted->id));
        foreach ($kept as $group) {
            $this->assertNotNull(ContactGroup::find($group->id), "{$group->name} was deleted");
        }
    }

    public function test_other_organizations_are_untouched(): void
    {
        $otherOrganization = Organization::factory()->create(['created_by' => $this->user->id]);
        $foreign = $this->group('Men 4521', $otherOrganization);
        $foreignContact = $this->contact('عميل منشأة أخرى', '+966500000003', $otherOrganization);
        $foreign->contacts()->attach($foreignContact->id);

        $this->runMigration();

        $this->assertNotNull(ContactGroup::find($foreign->id));
        $this->assertSame(1, $foreign->contacts()->count());
    }

    public function test_a_group_used_by_a_campaign_is_kept(): void
    {
        $usedByCampaign = $this->group('Men 3000');
        $unused = $this->group('Men 3001');
        Campaign::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'حملة',
            'status' => 'completed',
            'template_id' => 0,
            'contact_group_id' => $usedByCampaign->id,
            'metadata' => '{}',
            'created_by' => $this->user->id,
        ]);

        $this->runMigration();

        $this->assertNotNull(ContactGroup::find($usedByCampaign->id));
        $this->assertNull(ContactGroup::withTrashed()->find($unused->id));
    }

    public function test_running_it_twice_changes_nothing_more(): void
    {
        $this->group('Men 4521');
        $kept = $this->group('ذكر');

        $this->runMigration();
        $this->runMigration();

        $this->assertSame([$kept->id], ContactGroup::withTrashed()->where('organization_id', $this->organization->id)->pluck('id')->all());
    }

    public function test_it_does_nothing_when_the_organization_does_not_exist(): void
    {
        DB::table('organizations')->where('id', $this->organization->id)->update(['uuid' => (string) Str::uuid()]);
        $group = $this->group('Men 4521');

        $this->runMigration();

        $this->assertNotNull(ContactGroup::find($group->id));
    }

    private function runMigration(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->up();
    }

    private function organizationWithUuid(string $uuid): Organization
    {
        // HasUuid يولّد uuid جديداً عند الإنشاء، فيُثبَّت المطلوب بعده.
        $organization = Organization::factory()->create(['created_by' => $this->user->id]);
        DB::table('organizations')->where('id', $organization->id)->update(['uuid' => $uuid]);

        return $organization->fresh();
    }

    private function group(string $name, ?Organization $organization = null): ContactGroup
    {
        return ContactGroup::create([
            'organization_id' => ($organization ?? $this->organization)->id,
            'name' => $name,
            'created_by' => $this->user->id,
        ]);
    }

    private function contact(string $firstName, string $phone, ?Organization $organization = null): Contact
    {
        return Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => ($organization ?? $this->organization)->id,
            'first_name' => $firstName,
            'phone' => $phone,
            'created_by' => $this->user->id,
        ]);
    }
}
