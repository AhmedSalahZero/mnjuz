<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * صلاحية العضو داخل المنشأة في قائمة الفريق.
 *
 * في النظام صلاحيتان: `users.role` على مستوى المنصّة كلّها (admin/user)،
 * و`teams.role` داخل المنشأة (owner/manager/agent). وكانت النقطة تُرجع
 * الأولى — فتعود `user` لكل الأعضاء تقريباً (404 من 406 في الإنتاج)، ولا
 * يميّز التطبيق مالكاً من موظّف.
 *
 * صار `role` يحمل صلاحية المنشأة. تغييرٌ كاسر مقصود: التطبيق يقرأ الحقل
 * نفسه فيجد معناه قد تبدّل، فلا بدّ من إصدار يوافقه.
 */
class MobileTeamRoleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);

        Team::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'created_by' => $this->owner->id,
        ]);

        Addon::factory()->create(['name' => 'Google Authenticator']);
        Addon::factory()->create(['name' => 'Mobile App', 'status' => 1, 'is_active' => 1]);
        Setting::create(['key' => 'storage_system', 'value' => 'local']);

        $plan = SubscriptionPlan::create([
            'name' => 'P', 'price' => 0, 'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => -1]),
        ]);
        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        $this->owner->forceFill(['current_mobile_organization_id' => $this->organization->id])->save();
        $this->actingAs($this->owner, 'sanctum');
    }

    private function member(string $teamRole, string $userRole = 'user', ?int $organizationId = null): User
    {
        $user = User::factory()->create(['role' => $userRole]);

        Team::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId ?? $this->organization->id,
            'role' => $teamRole,
            'created_by' => $this->owner->id,
        ]);

        return $user;
    }

    private function members(): array
    {
        $response = $this->getJson('/api/v1/list-teams');
        $response->assertOk();

        return collect($response->json('data'))->keyBy('id')->all();
    }

    // ------------------------------------------------- الإصلاح

    /** كل صلاحية داخل المنشأة تعود كما هي، لا `user` للجميع. */
    public function test_each_member_returns_its_own_organization_role(): void
    {
        $manager = $this->member('manager');
        $agent = $this->member('agent');

        $members = $this->members();

        $this->assertSame('owner', $members[$this->owner->id]['role']);
        $this->assertSame('manager', $members[$manager->id]['role']);
        $this->assertSame('agent', $members[$agent->id]['role']);
    }

    /** العطل نفسه: لم تعد كل الصلاحيات قيمةً واحدة. */
    public function test_the_roles_are_not_all_identical_anymore(): void
    {
        $this->member('manager');
        $this->member('agent');

        $roles = collect($this->members())->pluck('role')->unique()->values();

        $this->assertCount(3, $roles, 'ثلاثة أعضاء بثلاث صلاحيات مختلفة');
    }

    // ------------------------------------------------- شكل الردّ

    /**
     * صلاحية المنصّة لا تُسرّب نفسها إلى الحقل.
     *
     * أدمن المنصّة قد يكون مديراً في منشأة وموظّفاً في أخرى — فالمُرجَع هو
     * صلاحيته هنا لا هناك.
     */
    public function test_the_platform_role_does_not_leak_into_the_field(): void
    {
        $admin = $this->member('manager', 'admin');

        $members = $this->members();

        $this->assertSame('manager', $members[$admin->id]['role'], 'المطلوب صلاحية المنشأة');
        $this->assertNotSame('admin', $members[$admin->id]['role'], 'لا صلاحية المنصّة');
    }

    /** ولا حقل جديد: الاسم القديم وحده. */
    public function test_no_extra_role_field_is_added(): void
    {
        $this->member('agent');

        foreach ($this->members() as $member) {
            $this->assertArrayNotHasKey('team_role', $member);
            $this->assertArrayNotHasKey('organization_role', $member);
        }
    }

    /** والحقول التي يقرأها التطبيق اليوم ما زالت موجودة. */
    public function test_the_existing_fields_are_still_returned(): void
    {
        $members = $this->members();
        $owner = $members[$this->owner->id];

        foreach (['id', 'first_name', 'last_name', 'email', 'role'] as $field) {
            $this->assertArrayHasKey($field, $owner, $field . ' اختفى من الردّ');
        }

        $this->assertArrayNotHasKey('password', $owner);
        $this->assertArrayNotHasKey('tfa_secret', $owner);
    }

    // ------------------------------------------------- النطاق

    /** أعضاء منشأة أخرى لا يظهرون. */
    public function test_members_of_another_organization_are_excluded(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $stranger = $this->member('owner', 'user', $other->id);

        $this->assertArrayNotHasKey($stranger->id, $this->members());
    }

    /** والعضو المُزال من الفريق كذلك. */
    public function test_a_removed_member_is_excluded(): void
    {
        $removed = $this->member('agent');
        DB::table('teams')->where('user_id', $removed->id)->update(['deleted_at' => now()]);

        $this->assertArrayNotHasKey($removed->id, $this->members());
    }

    /** الشكل العام للردّ لم يتغيّر. */
    public function test_the_response_envelope_is_unchanged(): void
    {
        $this->getJson('/api/v1/list-teams')
            ->assertOk()
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['statusCode', 'success', 'message', 'data']);
    }
}
