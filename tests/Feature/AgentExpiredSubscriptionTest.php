<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموظّف في منشأة انتهى اشتراكها.
 *
 * من الإنتاج: موظّفة تسجّل الدخول فتقرأ «ليس لديك صلاحية الوصول إلى هذا
 * القسم». وحسابها سليم تماماً — الذي انتهى اشتراك المنشأة.
 *
 * السلسلة: الدخول يقود إلى /dashboard، والموظّف يُحوَّل إلى /chats، وهي خلف
 * فحص الاشتراك فتُحوّله إلى /billing، و/billing ليست من صفحاته — فيُمنع
 * برسالة لا تدلّه على شيء ويظنّ أن حسابه تعطّل.
 *
 * والرسالة الآن تقول ما وقع، ومن يستطيع تجديده بالاسم.
 */
class AgentExpiredSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Addon::factory()->create(['name' => 'Google Authenticator']);
        // صفحة الفوترة تقرأ هذا الإعداد بلا احتياط من null.
        \App\Models\Setting::create(['key' => 'is_tax_inclusive', 'value' => '0']);

        $this->owner = User::factory()->create(['role' => 'user', 'first_name' => 'سالم', 'last_name' => 'المالك', 'email' => 'owner@test.sa']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);

        $this->manager = User::factory()->create(['role' => 'user', 'first_name' => 'نورة', 'last_name' => 'المديرة', 'email' => 'manager@test.sa']);
        $this->agent = User::factory()->create(['role' => 'user', 'first_name' => 'وفاء', 'last_name' => 'الموظفة', 'email' => 'agent@test.sa']);

        foreach ([[$this->owner, 'owner'], [$this->manager, 'manager'], [$this->agent, 'agent']] as [$user, $role]) {
            Team::factory()->create([
                'user_id' => $user->id,
                'organization_id' => $this->organization->id,
                'role' => $role,
                'created_by' => $this->owner->id,
            ]);
        }
    }

    private function subscription(string $status, $validUntil): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Plan', 'price' => 0, 'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => -1]),
        ]);

        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'valid_until' => $validUntil,
        ]);
    }

    private function asAgent(): void
    {
        $this->actingAs($this->agent);
        session(['current_organization' => $this->organization->id]);
    }

    // ------------------------------------------------- الرسالة

    /** العطل نفسه: كان يقرأ «ليس لديك صلاحية». */
    public function test_the_agent_is_told_the_subscription_expired(): void
    {
        $this->subscription('active', now()->subHours(6));
        $this->asAgent();

        $response = $this->get('/billing');

        $response->assertStatus(403);
        $response->assertSee(__('The organization subscription has expired'), false);
        $response->assertDontSee(__('You do not have permission to access this section.'), false);
    }

    /** ويُقال له إن حسابه سليم — وهو أوّل ما يخطر له. */
    public function test_the_message_says_the_account_itself_is_fine(): void
    {
        $this->subscription('active', now()->subDay());
        $this->asAgent();

        $this->get('/billing')->assertSee(
            __('Your account is fine. The subscription for this organization ended, so the workspace is paused until it is renewed.'),
            false
        );
    }

    /** ومن يستطيع التجديد بالاسم والبريد. */
    public function test_it_names_who_can_renew(): void
    {
        $this->subscription('active', now()->subDay());
        $this->asAgent();

        $response = $this->get('/billing');

        $response->assertSee('سالم المالك', false);
        $response->assertSee('owner@test.sa', false);
        $response->assertSee('نورة المديرة', false);
        $response->assertSee('manager@test.sa', false);
    }

    /** ولا يُذكر الموظّف بين من يستطيع. */
    public function test_agents_are_not_listed_as_renewers(): void
    {
        $this->subscription('active', now()->subDay());
        $this->asAgent();

        $this->get('/billing')->assertDontSee('agent@test.sa', false);
    }

    /** ولا أعضاء منشأة أخرى. */
    public function test_members_of_another_organization_are_not_listed(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $stranger = User::factory()->create(['role' => 'user', 'email' => 'stranger@test.sa']);
        Team::factory()->create([
            'user_id' => $stranger->id,
            'organization_id' => $other->id,
            'role' => 'owner',
            'created_by' => $this->owner->id,
        ]);

        $this->subscription('active', now()->subDay());
        $this->asAgent();

        $this->get('/billing')->assertDontSee('stranger@test.sa', false);
    }

    /** وعضو أُزيل من الفريق لا يُذكر. */
    public function test_a_removed_manager_is_not_listed(): void
    {
        Team::where('user_id', $this->manager->id)->update(['deleted_at' => now()]);

        $this->subscription('active', now()->subDay());
        $this->asAgent();

        $this->get('/billing')->assertDontSee('manager@test.sa', false);
    }

    // ------------------------------------------------- ما لم يتغيّر

    /**
     * الاشتراك فعّال: المنع يبقى منعاً.
     *
     * الرسالة الجديدة للاشتراك المنتهي وحده، لا لكل ممنوع.
     */
    public function test_a_valid_subscription_still_blocks_with_the_plain_message(): void
    {
        $this->subscription('active', now()->addMonth());
        $this->asAgent();

        $response = $this->get('/billing');

        $response->assertStatus(403);
        $response->assertDontSee(__('The organization subscription has expired'), false);
    }

    /**
     * والمالك والمدير يتجاوزان الحارس ليجدّدا — ولو كان الاشتراك منتهياً.
     *
     * فلو أوقفهما الحارس لعادت 403 وصفحة الانتهاء.
     */
    public function test_privileged_members_are_not_blocked_from_billing(): void
    {
        $this->subscription('active', now()->subDay());

        foreach ([$this->owner, $this->manager] as $user) {
            $this->actingAs($user);
            session(['current_organization' => $this->organization->id]);

            $this->get('/billing')->assertStatus(200);
        }
    }

    /** ولوحة التحكّم ما زالت تُحوّل الموظّف إلى المحادثات. */
    public function test_the_dashboard_still_redirects_the_agent_to_chats(): void
    {
        $this->subscription('active', now()->addMonth());
        $this->asAgent();

        $this->get('/dashboard')->assertRedirect('/chats');
    }

    /** والصفحات المسموحة تبقى مفتوحة له. */
    public function test_allowed_pages_stay_open(): void
    {
        $this->subscription('active', now()->addMonth());
        $this->asAgent();

        $this->get('/chats')->assertStatus(200);
    }
}
