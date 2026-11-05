<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * رسم الرسائل في لوحة التحكم، وهي أول صفحة بعد الدخول.
 *
 * من الإنتاج: مدير منشأة فيها 590 ألف رسالة يدخل فلا تفتح الصفحة. كان كل عدّ
 * يقارن DATE(created_at)، فيتعذّر استعمال created_at في الفهرس ويستغرق
 * الاستعلام ثوانيَ طويلة، وهي 14 استعلاماً في الصفحة الواحدة.
 */
class DashboardChatCountsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Organization $organization;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 12:00:00');

        Addon::factory()->create(['name' => 'Google Authenticator']);
        Setting::create(['key' => 'is_tax_inclusive', 'value' => '0']);

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);
        $this->manager = User::factory()->create(['role' => 'user']);

        Team::factory()->create([
            'user_id' => $this->manager->id,
            'organization_id' => $this->organization->id,
            'role' => 'manager',
            'created_by' => $owner->id,
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'Plan', 'price' => 0, 'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => -1]),
        ]);

        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addMonth(),
        ]);

        $this->contact = $this->contactIn($this->organization, $owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function contactIn(Organization $organization, User $creator): Contact
    {
        return Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'first_name' => 'عميل',
            'phone' => '+9665' . random_int(10000000, 99999999),
            'created_by' => $creator->id,
        ]);
    }

    private function chat(string $createdAt, string $type = 'inbound', array $overrides = []): void
    {
        Chat::create(array_merge([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'wam_id' => 'wamid.' . Str::random(12),
            'type' => $type,
            'status' => 'delivered',
            'is_read' => 1,
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'رسالة']]),
            'created_at' => $createdAt,
        ], $overrides));
    }

    private function openDashboard()
    {
        return $this->actingAs($this->manager)
            ->withSession(['current_organization' => $this->organization->id])
            ->get('/dashboard');
    }

    /** آخر عنصر هو اليوم، وقبله أمس، بترتيب period(). */
    public function test_each_message_lands_on_its_own_day(): void
    {
        $this->chat('2026-11-05 00:00:00');
        $this->chat('2026-11-05 23:59:59');
        $this->chat('2026-11-04 23:59:59');
        $this->chat('2026-11-04 00:00:00');
        $this->chat('2026-10-30 00:00:00');
        $this->chat('2026-10-29 23:59:59');
        $this->chat('2026-11-05 09:30:00', 'outbound');

        $this->openDashboard()
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('inbound', [1, 0, 0, 0, 0, 2, 2])
                ->where('outbound', [0, 0, 0, 0, 0, 0, 1]));
    }

    public function test_only_this_organizations_live_messages_are_counted(): void
    {
        $this->chat('2026-11-05 10:00:00');
        $this->chat('2026-11-05 10:00:00', 'inbound', ['chat_import_id' => 999]);
        $this->chat('2026-11-05 10:00:00', 'inbound', ['deleted_at' => '2026-11-05 11:00:00']);

        $owner = User::factory()->create(['role' => 'user']);
        $other = Organization::factory()->create(['created_by' => $owner->id]);
        $this->chat('2026-11-05 10:00:00', 'inbound', [
            'organization_id' => $other->id,
            'contact_id' => $this->contactIn($other, $owner)->id,
        ]);

        $this->openDashboard()
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('inbound', [0, 0, 0, 0, 0, 0, 1]));
    }

    /** DATE(created_at) يمنع الفهرس، فيبطئ الصفحة في المنشآت الكبيرة. */
    public function test_counts_filter_on_created_at_directly(): void
    {
        DB::enableQueryLog();

        $this->openDashboard()->assertOk();

        $chatQueries = array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $sql) => str_contains($sql, 'from `chats`')
        );

        $this->assertNotEmpty($chatQueries);

        foreach ($chatQueries as $sql) {
            $this->assertStringNotContainsString('date(', strtolower($sql));
        }
    }
}
