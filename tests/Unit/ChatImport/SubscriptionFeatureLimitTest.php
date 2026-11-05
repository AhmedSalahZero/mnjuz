<?php

namespace Tests\Unit\ChatImport;

use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * قيمة الحدّ كما تقرؤها isSubscriptionFeatureLimitReached — لكن بالرقم لا بنعم/لا.
 */
class SubscriptionFeatureLimitTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->organizationId = Organization::factory()->create(['created_by' => $user->id])->id;
    }

    private function subscribe(array $metadata, string $status = 'active', $validUntil = null): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Plan ' . Str::random(4),
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode($metadata),
        ]);

        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organizationId,
            'plan_id' => $plan->id,
            'status' => $status,
            'valid_until' => $validUntil ?? now()->addMonth(),
        ]);
    }

    public function test_plan_limits(): void
    {
        $this->subscribe(['contacts_limit' => 500, 'message_limit' => -1]);

        $this->assertSame(500, SubscriptionService::featureLimit($this->organizationId, 'contacts_limit'));
        $this->assertNull(SubscriptionService::featureLimit($this->organizationId, 'message_limit'), '-1 is unlimited');
        $this->assertNull(SubscriptionService::featureLimit($this->organizationId, 'campaign_limit'), 'a missing key is unlimited');
    }

    public function test_no_or_expired_subscription_allows_nothing(): void
    {
        $this->assertSame(0, SubscriptionService::featureLimit($this->organizationId, 'contacts_limit'));

        $this->subscribe(['contacts_limit' => -1], 'active', now()->subDay());
        $this->assertSame(0, SubscriptionService::featureLimit($this->organizationId, 'contacts_limit'));
    }

    public function test_trial_limits_come_from_settings(): void
    {
        Setting::create(['key' => 'trial_limits', 'value' => json_encode(['contacts' => 100, 'messages' => -1])]);
        $this->subscribe(['contacts_limit' => 999999], 'trial');

        $this->assertSame(100, SubscriptionService::featureLimit($this->organizationId, 'contacts_limit'));
        $this->assertNull(SubscriptionService::featureLimit($this->organizationId, 'message_limit'));
    }

    public function test_it_agrees_with_the_limit_reached_check(): void
    {
        $this->subscribe(['contacts_limit' => 0]);

        $this->assertSame(0, SubscriptionService::featureLimit($this->organizationId, 'contacts_limit'));
        $this->assertTrue(SubscriptionService::isSubscriptionFeatureLimitReached($this->organizationId, 'contacts_limit'));
    }
}
