<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Services\CampaignAudienceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FlowBuilder\Services\ActionExecutionService;
use Modules\FlowBuilder\Validators\FlowValidator;
use Tests\TestCase;

/**
 * إجراء «إعادة الاشتراك في العروض» في المسارات.
 *
 * الانسحاب (Remove from Group) علامة لا تُلغيها الإضافة إلى مجموعة، ولم يكن في
 * النظام ما يمسحها. فمسار مثل «ايقاف الاعلانات» يردّ على من ضغط «إعادة
 * الإشتراك» بـ«لن يفوتك أي عرض» ويُبقيه خارج كل حملة — وتعرضه المجموعة
 * عضواً وترفضه الحملة. هذا الإجراء طريق العودة بطلب العميل.
 */
class MarketingResubscribeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // باني ActionExecutionService يُنشئ WhatsappService، وهو يقرأ مفاتيح
        // Pusher من جدول الإعدادات بلا قيمة بديلة.
        foreach (['pusher_app_key', 'pusher_app_secret', 'pusher_app_id', 'pusher_app_cluster'] as $key) {
            Setting::create(['key' => $key, 'value' => 'test']);
        }

        $this->user = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->user->id]);
    }

    public function test_it_clears_the_opt_out(): void
    {
        $contact = $this->contact('عميل');
        $this->runAction('remove_from_group', $contact);
        $this->assertNotNull($contact->fresh()->marketing_opted_out_at);

        $this->assertTrue($this->runAction('resubscribe_marketing', $contact->fresh()));

        $this->assertNull($contact->fresh()->marketing_opted_out_at);
    }

    /** السيناريو كما وقع: انسحاب، ثم «إعادة الإشتراك» تُضيفه إلى مجموعة. */
    public function test_the_resubscribed_contact_is_back_in_group_and_everyone_campaigns(): void
    {
        $group = $this->group('إعادة الاشتراك في تلقي العروض');
        $contact = $this->contact('عميل');

        $this->runAction('remove_from_group', $contact);
        $this->runAction('add_to_group', $contact->fresh(), ['group_id' => $group->id]);

        // الإضافة وحدها لا تكفي — هذا هو البلاغ.
        $this->assertSame([], $this->audience($group->id));
        $this->assertTrue(CampaignAudienceService::isEmpty($this->organization->id, $group->id));

        $this->runAction('resubscribe_marketing', $contact->fresh());

        $this->assertSame([$contact->id], $this->audience($group->id));
        $this->assertSame([$contact->id], $this->audience(0));
    }

    public function test_a_contact_that_never_opted_out_is_unchanged(): void
    {
        $contact = $this->contact('عميل');
        $service = new ActionExecutionService($contact->organization_id);

        // Contact بلا timestamps، فالدليل أن لا استعلام UPDATE يصل القاعدة.
        DB::enableQueryLog();
        $this->assertTrue((bool) $service->executeAction('resubscribe_marketing', [], $contact));
        $updates = collect(DB::getQueryLog())->filter(fn (array $query) => str_starts_with(strtolower($query['query']), 'update'));
        DB::disableQueryLog();

        $this->assertCount(0, $updates, 'nothing to save for a subscribed contact');
        $this->assertNull($contact->fresh()->marketing_opted_out_at);
    }

    /** لا يُعيد المجموعات التي نُزع منها: لا سجلّ بها، والتاجر يضيف ما يريد بعده. */
    public function test_it_does_not_touch_group_membership(): void
    {
        $vip = $this->group('VIP');
        $contact = $this->contact('عميل');
        $contact->contactGroups()->attach($vip->id);
        $this->runAction('remove_from_group', $contact);

        $this->runAction('resubscribe_marketing', $contact->fresh());

        $this->assertSame([], $contact->fresh()->contactGroups()->pluck('contact_groups.id')->all());
    }

    public function test_it_only_affects_the_contact_itself(): void
    {
        $ours = $this->contact('عميل');
        $other = $this->contact('عميل آخر');
        $otherOrganization = Organization::factory()->create(['created_by' => $this->user->id]);
        $foreign = $this->contact('منشأة أخرى', $otherOrganization);
        foreach ([$ours, $other, $foreign] as $contact) {
            $this->runAction('remove_from_group', $contact);
        }

        $this->runAction('resubscribe_marketing', $ours->fresh());

        $this->assertNull($ours->fresh()->marketing_opted_out_at);
        $this->assertNotNull($other->fresh()->marketing_opted_out_at);
        $this->assertNotNull($foreign->fresh()->marketing_opted_out_at);
    }

    // ------------------------------------------- الإعداد والتحقّق

    public function test_the_action_is_enabled_for_the_flow_builder(): void
    {
        $this->assertTrue(config('FlowBuilder.actions')['resubscribe_marketing'] ?? false);
    }

    public function test_the_flow_validator_accepts_it_without_settings(): void
    {
        $result = (new FlowValidator())->validateMessageNodes(['nodes' => [
            ['id' => '1', 'type' => 'start', 'data' => ['metadata' => ['fields' => ['type' => 'keywords', 'keywords' => 'إيقاف تلقي العروض']]]],
            ['id' => '2', 'type' => 'action', 'data' => ['actionType' => 'resubscribe_marketing', 'config' => [], 'is_active' => true]],
        ]]);

        $this->assertTrue($result, json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------- أدوات

    private function runAction(string $action, Contact $contact, array $config = []): bool
    {
        return (bool) (new ActionExecutionService($contact->organization_id))
            ->executeAction($action, $config, $contact);
    }

    /** @return array<int, int> */
    private function audience(int $groupId): array
    {
        return CampaignAudienceService::query($this->organization->id, $groupId)
            ->pluck('contacts.id')->sort()->values()->all();
    }

    private function contact(string $name, ?Organization $organization = null): Contact
    {
        return Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => ($organization ?? $this->organization)->id,
            'first_name' => $name,
            'phone' => '+9665' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'created_by' => $this->user->id,
        ]);
    }

    private function group(string $name): ContactGroup
    {
        return ContactGroup::create([
            'organization_id' => $this->organization->id,
            'name' => $name,
            'created_by' => $this->user->id,
        ]);
    }
}
