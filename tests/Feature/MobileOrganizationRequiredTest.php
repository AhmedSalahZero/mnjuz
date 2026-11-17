<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Team;
use Illuminate\Support\Str;
use Tests\Feature\Cycle\CycleTestCase;

/**
 * مستخدمٌ في أكثر من منشأة وعمود منشأة التطبيق عنده فارغ: الخادم لا يختار
 * عنه (قرارٌ مقصود)، فكل طلب v1 يأخذ 403.
 *
 * هذا ما علق فيه مالك Waz (2026-11-17): عموده مُسح قبل إصلاح المستمع، فبقي
 * التطبيق يعرض «التحديثات المباشرة غير متاحة» بدل أن يفتح اختيار المنشأة.
 * الردّ يحمل الآن رمزاً ثابتاً (organization_required) لا يتغيّر بلغة الرسالة،
 * والخروج منه باختيار المنشأة (set-current-organization) يعمل من هذه الحالة.
 */
class MobileOrganizationRequiredTest extends CycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Addon::create([
            'uuid' => (string) Str::uuid(),
            'category' => 'mobile',
            'name' => 'Mobile App',
            'logo' => 'mobile.png',
            'status' => 1,
            'is_active' => 1,
        ]);

        // منشأة ثانية: بمنشأة واحدة يختارها الخادم تلقائياً فلا تقع الحالة.
        $second = Organization::factory()->create(['created_by' => $this->owner->id]);
        Team::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $second->id,
            'role' => 'owner',
            'created_by' => $this->owner->id,
        ]);

        $this->owner->forceFill(['current_mobile_organization_id' => null])->save();
    }

    public function test_a_v1_call_without_a_mobile_organization_returns_the_stable_code(): void
    {
        $this->actingAs($this->owner->fresh(), 'sanctum');

        $this->getJson('/api/v1/contacts')
            ->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'message' => __('Mobile App requires a selected organization. Please set your current organization.'),
                'code' => 'organization_required',
            ]);

        // الخادم لم يختر عنه منشأةً من تلقاء نفسه.
        $this->assertNull($this->owner->fresh()->current_mobile_organization_id);
    }

    public function test_the_selected_organization_guard_outside_v1_carries_the_same_code(): void
    {
        $this->actingAs($this->owner->fresh(), 'sanctum');

        $this->getJson('/api/payments/myfatoorah/status/1')
            ->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'message' => __('Please select an organization to continue.'),
                'code' => 'organization_required',
            ]);
    }

    public function test_selecting_the_organization_from_that_state_restores_v1_calls(): void
    {
        $this->actingAs($this->owner->fresh(), 'sanctum');
        $this->getJson('/api/v1/contacts')->assertStatus(403);

        $this->postJson('/api/v1/auth/set-current-organization', ['organization_id' => $this->organization->id])
            ->assertOk()
            ->assertJsonPath('data.current_organization_id', $this->organization->id);

        $this->assertSame($this->organization->id, (int) $this->owner->fresh()->current_mobile_organization_id);

        $this->actingAs($this->owner->fresh(), 'sanctum');
        $this->getJson('/api/v1/contacts')
            ->assertOk()
            ->assertJsonMissingPath('code');
    }
}
