<?php

namespace Tests\Feature\MobileApi;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * أرضية مشتركة لنقاط التطبيق الجديدة.
 *
 * كل نقطة تمرّ بأربع بوّابات قبل أن تصل المتحكّم: sanctum، وإضافة تطبيق
 * الجوال، واشتراك فعّال، ومنشأة مختارة. فبناؤها في مكان واحد يمنع أن يمرّ
 * اختبار لسبب خاطئ — كأن يعود 403 من البوّابة لا من الصلاحية التي نختبرها.
 */
abstract class MobileApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;
    protected User $owner;
    protected SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create([
            'created_by' => $this->owner->id,
            'metadata' => json_encode(['timezone' => 'Asia/Riyadh']),
        ]);

        Team::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'created_by' => $this->owner->id,
        ]);

        Addon::factory()->create(['name' => 'Google Authenticator']);
        Addon::factory()->create(['name' => 'Mobile App', 'status' => 1, 'is_active' => 1]);
        Addon::factory()->create(['name' => 'Working Hours', 'status' => 1, 'is_active' => 1]);
        Setting::create(['key' => 'storage_system', 'value' => 'local']);

        $this->plan = SubscriptionPlan::create([
            'name' => 'Test Plan',
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode($this->planMetadata()),
        ]);

        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        $this->actAs($this->owner);
    }

    /** @return array<string, mixed> */
    protected function planMetadata(): array
    {
        return [
            'message_limit' => -1,
            'agent_performance' => 1,
            'activity_log' => 1,
            'rating_delete' => 1,
            'addons' => ['Working Hours' => true],
        ];
    }

    protected function actAs(User $user): void
    {
        $user->forceFill(['current_mobile_organization_id' => $this->organization->id])->save();
        $this->actingAs($user, 'sanctum');
    }

    /** عضو بصلاحية داخل المنشأة نفسها. */
    protected function member(string $role): User
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
}
