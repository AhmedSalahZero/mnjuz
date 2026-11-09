<?php

namespace Tests\Feature\WebChat;

use App\Events\NewChatEvent;
use App\Events\WebChatVisitorEvent;
use App\Jobs\ProcessAutoReplyJob;
use App\Models\ChannelAccount;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Models\WebChatVisitor;
use App\Services\WebChat\WebChatWidgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * منشأة باشتراك فيه «Web Chat»، وتذاكر مفعّلة، و widget جاهز — وأدوات
 * الزائر: فتح جلسة وإرسال رسالة.
 */
abstract class WebChatTestCase extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;
    protected ChannelAccount $widget;
    protected SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        // بقائمة محدّدة لا fake() عامّة: تلك تُعطّل مولّد uuid في الموديلات.
        Event::fake([NewChatEvent::class, WebChatVisitorEvent::class]);
        // الردّ الآلي يُختبر في مرحلته؛ هنا يكفي أن يُطلَق.
        Bus::fake([ProcessAutoReplyJob::class]);

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);

        $this->plan = SubscriptionPlan::create([
            'name' => 'Plan',
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(['addons' => ['Web Chat' => true]]),
        ]);
        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        // التذاكر مفعّلة كما عند أغلب العملاء: الرسالة الأولى تفتح تذكرة.
        $this->organization->update(['metadata' => json_encode(['tickets' => ['active' => true, 'assignment_mode' => 'manual']])]);

        $this->widget = app(WebChatWidgetService::class)->ensureForOrganization($this->organization->id, 'متجر الاختبار');
    }

    protected function url(string $path, ?ChannelAccount $widget = null): string
    {
        return '/api/webchat/v1/w/' . ($widget ?? $this->widget)->external_id . '/' . $path;
    }

    /** @return array{0: string, 1: WebChatVisitor} */
    protected function openSession(array $overrides = []): array
    {
        $response = $this->postJson($this->url('sessions'), $overrides + [
            'name' => 'سارة',
            'phone' => '+966 50 123 4567',
            'elapsed_ms' => 4000,
            'page_url' => 'https://shop.example.com/products/1',
        ])->assertCreated();

        return [$response->json('token'), WebChatVisitor::where('uuid', $response->json('visitor.id'))->first()];
    }

    protected function send(string $token, array $body): TestResponse
    {
        return $this->postJson($this->url('messages'), $body + [
            'client_message_id' => (string) Str::uuid(),
            'type' => 'text',
        ], ['X-Webchat-Token' => $token]);
    }

    protected function setSettings(array $settings): void
    {
        $this->widget->forceFill(['settings' => $settings + ['enabled' => true]])->save();
    }

    protected function actAsOwner(): User
    {
        $owner = User::find($this->organization->created_by);
        Team::firstOrCreate(
            ['user_id' => $owner->id, 'organization_id' => $this->organization->id],
            ['role' => 'owner', 'status' => 'active', 'created_by' => $owner->id]
        );

        $this->actingAs($owner);
        session(['current_organization' => $this->organization->id]);

        return $owner;
    }
}
