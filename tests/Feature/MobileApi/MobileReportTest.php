<?php

namespace Tests\Feature\MobileApi;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\ConversationRating;
use App\Services\ActivityLogger;
use Illuminate\Support\Str;

/**
 * تقارير التطبيق: الأداء والتقييمات وسجلّ النشاط.
 *
 * الثلاثة للمالك والمدير وحدهما — الموظّف لا يرى أداء زملائه ولا تقييماتهم.
 */
class MobileReportTest extends MobileApiTestCase
{
    private function contact(): Contact
    {
        return Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'عميل',
            'phone' => '+9665' . random_int(10000000, 99999999),
            'created_by' => $this->owner->id,
        ]);
    }

    private function rating(int $stars, array $overrides = []): ConversationRating
    {
        return ConversationRating::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact()->id,
            'contact_name' => 'عميل راضٍ',
            'contact_phone' => '+966500000001',
            'agent_name' => 'أحمد',
            'token' => Str::random(32),
            'rating' => $stars,
            'comment' => 'خدمة ممتازة',
            'status' => ConversationRating::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ], $overrides));
    }

    private function activity(string $event = ActivityLogger::MESSAGE_SENT): ActivityLog
    {
        return ActivityLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'user_name' => 'أحمد صلاح',
            'event' => $event,
            'subject_type' => 'contact',
            'subject_label' => 'عميل',
            'created_at' => now(),
        ]);
    }

    // ------------------------------------------------- أداء الموظفين

    public function test_it_returns_agent_performance(): void
    {
        $this->getJson('/api/v1/reports/agent-performance')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['metrics', 'filters' => ['from', 'to']]]);
    }

    /** الافتراضي آخر ثلاثين يوماً — نفس ما يفتح به الويب. */
    public function test_the_default_range_is_the_last_thirty_days(): void
    {
        $response = $this->getJson('/api/v1/reports/agent-performance');

        $this->assertSame(now()->subDays(29)->toDateString(), $response->json('data.filters.from'));
        $this->assertSame(now()->toDateString(), $response->json('data.filters.to'));
    }

    public function test_the_range_can_be_chosen(): void
    {
        $this->getJson('/api/v1/reports/agent-performance?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->assertJsonPath('data.filters.from', '2026-01-01')
            ->assertJsonPath('data.filters.to', '2026-01-31');
    }

    public function test_an_agent_cannot_see_performance(): void
    {
        $this->actAs($this->member('agent'));

        $this->getJson('/api/v1/reports/agent-performance')->assertStatus(403);
    }

    /** والميزة خارج الباقة ⇒ الشاشة لا تُفتح. */
    public function test_performance_requires_the_plan_feature(): void
    {
        $this->plan->forceFill(['metadata' => json_encode(['message_limit' => -1])])->save();

        $this->getJson('/api/v1/reports/agent-performance')->assertStatus(403);
    }

    // ------------------------------------------------- التقييمات

    public function test_it_lists_ratings_with_a_summary(): void
    {
        $this->rating(5);
        $this->rating(3);

        $response = $this->getJson('/api/v1/reports/ratings');

        $response->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.average', 4);
    }

    /** الملخّص يتبع الترشيح لا الصفحة المعروضة. */
    public function test_the_summary_follows_the_filter(): void
    {
        $this->rating(5);
        $this->rating(1);

        $this->getJson('/api/v1/reports/ratings?rating=5')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.average', 5);
    }

    public function test_it_searches_ratings(): void
    {
        $this->rating(5, ['contact_name' => 'سارة']);
        $this->rating(4, ['contact_name' => 'خالد']);

        $this->getJson('/api/v1/reports/ratings?search=سارة')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.contact_name', 'سارة');
    }

    /** التقييمات المعلّقة تُعدّ ولا تُعرض: العميل لم يُجب بعد. */
    public function test_pending_ratings_are_counted_not_listed(): void
    {
        $this->rating(5);
        $this->rating(0, ['status' => ConversationRating::STATUS_PENDING, 'submitted_at' => null]);

        $this->getJson('/api/v1/reports/ratings')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.summary.pending', 1);
    }

    public function test_an_agent_cannot_see_ratings(): void
    {
        $this->actAs($this->member('agent'));

        $this->getJson('/api/v1/reports/ratings')->assertStatus(403);
    }

    // ------------------------------------------------- حذف تقييم

    public function test_the_owner_can_delete_a_rating(): void
    {
        $rating = $this->rating(1);

        $this->deleteJson('/api/v1/reports/ratings/' . $rating->uuid)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull(ConversationRating::find($rating->id));
    }

    /** المدير لا يحذف — الحذف للمالك وحده كما في الويب. */
    public function test_a_manager_cannot_delete_a_rating(): void
    {
        $rating = $this->rating(1);
        $this->actAs($this->member('manager'));

        $this->deleteJson('/api/v1/reports/ratings/' . $rating->uuid)->assertStatus(403);
        $this->assertNotNull(ConversationRating::find($rating->id));
    }

    public function test_deleting_an_unknown_rating_is_a_404(): void
    {
        $this->deleteJson('/api/v1/reports/ratings/' . Str::uuid())->assertStatus(404);
    }

    /** ولا يُحذف تقييم منشأة أخرى ولو عُرف معرّفه. */
    public function test_a_rating_of_another_organization_cannot_be_deleted(): void
    {
        $rating = $this->rating(1, ['organization_id' => $this->organization->id + 999]);

        $this->deleteJson('/api/v1/reports/ratings/' . $rating->uuid)->assertStatus(404);
        $this->assertNotNull(ConversationRating::find($rating->id));
    }

    // ------------------------------------------------- سجلّ النشاط

    public function test_it_lists_activity(): void
    {
        $this->activity();
        $this->activity();

        $this->getJson('/api/v1/reports/activity-log')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.retention_days', ActivityLogger::RETENTION_DAYS)
            ->assertJsonStructure(['data' => ['members', 'groups']]);
    }

    public function test_activity_can_be_filtered_by_user(): void
    {
        $this->activity();

        $this->getJson('/api/v1/reports/activity-log?user_id=' . ($this->owner->id + 999))
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_an_agent_cannot_see_the_activity_log(): void
    {
        $this->activity();
        $this->actAs($this->member('agent'));

        $this->getJson('/api/v1/reports/activity-log')->assertStatus(403);
    }

    public function test_the_activity_log_requires_the_plan_feature(): void
    {
        $this->plan->forceFill(['metadata' => json_encode(['message_limit' => -1])])->save();

        $this->getJson('/api/v1/reports/activity-log')->assertStatus(403);
    }
}
