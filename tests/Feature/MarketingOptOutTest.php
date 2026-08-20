<?php

namespace Tests\Feature;

use App\Jobs\CreateCampaignLogsJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FlowBuilder\Services\ActionExecutionService;
use Tests\TestCase;

/**
 * انسحاب جهة الاتصال من الرسائل التسويقية عبر إجراء «Remove from Group».
 *
 * كان الإجراء يطلب اختيار مجموعة، فمن لديه مئة مجموعة يحتاج مئة عقدة. وحتى مع
 * ذلك لم يكن يمنع شيئاً: الحملة الموجّهة «للكل» تختار كل جهات اتصال المنشأة،
 * بمجموعة أو بلا مجموعة — فمن أُزيل من مجموعته كانت تصله الحملة التالية.
 *
 * صار الإجراء ينزع جهة الاتصال من كل مجموعات المنشأة ويعلّمها منسحبةً، ويُحترم
 * الانسحاب في كل مسارات الحملات.
 */
class MarketingOptOutTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // باني ActionExecutionService يُنشئ WhatsappService، وهو يقرأ مفاتيح
        // Pusher من جدول الإعدادات بلا قيمة بديلة فيسقط على قاعدة نظيفة.
        foreach (['pusher_app_key', 'pusher_app_secret', 'pusher_app_id', 'pusher_app_cluster'] as $key) {
            Setting::create(['key' => $key, 'value' => 'test']);
        }

        $this->user = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->user->id]);
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

    private function group(string $name, ?Organization $organization = null): ContactGroup
    {
        return ContactGroup::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => ($organization ?? $this->organization)->id,
            'name' => $name,
            'created_by' => $this->user->id,
        ]);
    }

    private function runRemoveFromGroup(Contact $contact): bool
    {
        $service = new ActionExecutionService($contact->organization_id);
        $method = new \ReflectionMethod(ActionExecutionService::class, 'executeRemoveFromGroup');
        $method->setAccessible(true);

        return (bool) $method->invoke($service, [], $contact);
    }

    // ------------------------------------------- الإجراء نفسه

    /**
     * جوهر الطلب: عقدة واحدة تكفي مهما بلغ عدد المجموعات — لا مئة عقدة لمئة
     * مجموعة.
     */
    public function test_the_contact_leaves_every_group_in_one_step(): void
    {
        $contact = $this->contact('Ahmed');
        $groups = collect(range(1, 12))->map(fn ($i) => $this->group("Group {$i}"));
        $contact->contactGroups()->attach($groups->pluck('id')->all());

        $this->assertCount(12, $contact->fresh()->contactGroups);

        $this->assertTrue($this->runRemoveFromGroup($contact));

        $this->assertCount(0, $contact->fresh()->contactGroups, 'لم تُنزع من كل المجموعات');
    }

    public function test_the_contact_is_marked_as_opted_out(): void
    {
        $contact = $this->contact('Ahmed');
        $this->assertNull($contact->marketing_opted_out_at);

        $this->runRemoveFromGroup($contact);

        $this->assertNotNull($contact->fresh()->marketing_opted_out_at);
    }

    /** تاريخ أول انسحاب هو ما يُحتجّ به، فلا يُعاد ضبطه بمرور ثانٍ. */
    public function test_passing_the_node_again_keeps_the_original_opt_out_date(): void
    {
        $contact = $this->contact('Ahmed');
        $this->runRemoveFromGroup($contact);
        $first = $contact->fresh()->marketing_opted_out_at;

        $this->travel(2)->days();
        $this->runRemoveFromGroup($contact->fresh());

        $this->assertEquals($first, $contact->fresh()->marketing_opted_out_at);
    }

    /** جهة اتصال بلا مجموعات تنسحب أيضاً — العلامة مستقلّة عن العضوية. */
    public function test_a_contact_with_no_groups_still_opts_out(): void
    {
        $contact = $this->contact('Ahmed');

        $this->assertTrue($this->runRemoveFromGroup($contact));
        $this->assertNotNull($contact->fresh()->marketing_opted_out_at);
    }

    /** العزل: لا تُمسّ مجموعات منشأة أخرى ولو انتمت إليها جهة الاتصال خطأً. */
    public function test_groups_of_another_organization_are_untouched(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->user->id]);
        $ourGroup = $this->group('Ours');
        $foreignGroup = $this->group('Theirs', $other);

        $contact = $this->contact('Ahmed');
        $contact->contactGroups()->attach([$ourGroup->id, $foreignGroup->id]);

        $this->runRemoveFromGroup($contact);

        $remaining = $contact->fresh()->contactGroups->pluck('id')->all();
        $this->assertSame([$foreignGroup->id], $remaining);
    }

    /** مجموعة محذوفة لا تُعطّل الإجراء. */
    public function test_soft_deleted_groups_do_not_break_the_action(): void
    {
        $live = $this->group('Live');
        $deleted = $this->group('Deleted');
        DB::table('contact_groups')->where('id', $deleted->id)->update(['deleted_at' => now()]);

        $contact = $this->contact('Ahmed');
        $contact->contactGroups()->attach([$live->id, $deleted->id]);

        $this->assertTrue($this->runRemoveFromGroup($contact));
        $this->assertNotNull($contact->fresh()->marketing_opted_out_at);
    }

    // --------------------------------- أثره على الحملات

    private function campaign(?ContactGroup $group): Campaign
    {
        $template = Template::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'meta_id' => (string) Str::uuid(),
            'name' => 'promo',
            'language' => 'ar',
            'category' => 'MARKETING',
            'status' => 'APPROVED',
            'metadata' => json_encode([]),
            'created_by' => $this->user->id,
        ]);

        return Campaign::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'حملة',
            'template_id' => $template->id,
            'contact_group_id' => $group?->id ?? 0,
            'metadata' => json_encode(['header' => null, 'body' => null, 'footer' => null, 'buttons' => null]),
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
            'created_by' => $this->user->id,
        ]);
    }

    /** @return array<int, int> معرّفات من أُنشئ لهم سجلّ إرسال */
    private function targetedContactIds(Campaign $campaign): array
    {
        $job = new CreateCampaignLogsJob();
        $method = new \ReflectionMethod(CreateCampaignLogsJob::class, 'getContactsForCampaign');
        $method->setAccessible(true);

        return $method->invoke($job, $campaign)->pluck('id')->sort()->values()->all();
    }

    /**
     * العلّة الأصلية: حملة «للكل» كانت تصل من أُزيل من مجموعته، لأن الاختيار
     * لا يمرّ بالمجموعات أصلاً.
     */
    public function test_a_send_to_everyone_campaign_skips_opted_out_contacts(): void
    {
        $staying = $this->contact('Staying');
        $leaving = $this->contact('Leaving');
        $this->runRemoveFromGroup($leaving);

        $targeted = $this->targetedContactIds($this->campaign(null));

        $this->assertContains($staying->id, $targeted);
        $this->assertNotContains($leaving->id, $targeted, 'المنسحب يجب ألّا تصله حملة «للكل»');
    }

    /**
     * والانسحاب يبقى قائماً لو أعاد التاجر إدراجه في مجموعة: هو قرار العميل
     * لا نتيجة عضويته.
     */
    public function test_the_opt_out_survives_being_added_back_to_a_group(): void
    {
        $group = $this->group('VIP');
        $contact = $this->contact('Leaving');
        $this->runRemoveFromGroup($contact);
        $contact->contactGroups()->attach($group->id);

        $this->assertNotContains($contact->id, $this->targetedContactIds($this->campaign($group)));
        $this->assertNotContains($contact->id, $this->targetedContactIds($this->campaign(null)));
    }

    public function test_a_group_campaign_still_reaches_everyone_who_did_not_opt_out(): void
    {
        $group = $this->group('VIP');
        $a = $this->contact('A');
        $b = $this->contact('B');
        $c = $this->contact('C');
        foreach ([$a, $b, $c] as $contact) {
            $contact->contactGroups()->attach($group->id);
        }
        $this->runRemoveFromGroup($b);

        $targeted = $this->targetedContactIds($this->campaign($group));

        $this->assertContains($a->id, $targeted);
        $this->assertContains($c->id, $targeted);
        $this->assertNotContains($b->id, $targeted);
    }

    /** المسار القديم SendCampaignJob يحترم الانسحاب أيضاً — ما زال في الكود. */
    public function test_the_legacy_campaign_path_also_skips_opted_out_contacts(): void
    {
        $staying = $this->contact('Staying');
        $leaving = $this->contact('Leaving');
        $this->runRemoveFromGroup($leaving);

        $job = new \App\Jobs\SendCampaignJob();
        $method = new \ReflectionMethod(\App\Jobs\SendCampaignJob::class, 'getContactsForCampaign');
        $method->setAccessible(true);
        $targeted = $method->invoke($job, $this->campaign(null))->pluck('id')->all();

        $this->assertContains($staying->id, $targeted);
        $this->assertNotContains($leaving->id, $targeted);
    }

    /** لا سجلّ إرسال يُنشأ للمنسحب — الفحص على ما يُكتب فعلاً لا على الاستعلام. */
    public function test_no_campaign_log_is_created_for_an_opted_out_contact(): void
    {
        $staying = $this->contact('Staying');
        $leaving = $this->contact('Leaving');
        $this->runRemoveFromGroup($leaving);

        $campaign = $this->campaign(null);
        $job = new CreateCampaignLogsJob();
        $process = new \ReflectionMethod(CreateCampaignLogsJob::class, 'processCampaign');
        $process->setAccessible(true);
        $process->invoke($job, $campaign);

        $logged = CampaignLog::where('campaign_id', $campaign->id)->pluck('contact_id')->all();

        $this->assertContains($staying->id, $logged);
        $this->assertNotContains($leaving->id, $logged);
    }

    /** المنشآت الأخرى لا تتأثّر بانسحاب في منشأتنا. */
    public function test_opting_out_does_not_leak_across_organizations(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->user->id]);
        $ours = $this->contact('Ours');
        $theirs = $this->contact('Theirs', $other);

        $this->runRemoveFromGroup($ours);

        $this->assertNull($theirs->fresh()->marketing_opted_out_at);
    }

    // ------------------------------------------- الإعداد والتحقّق

    /** العقدة لم تعد تشترط مجموعة: هذا هو ما يجعل عقدةً واحدة كافية. */
    public function test_the_flow_validator_no_longer_requires_a_group(): void
    {
        $source = file_get_contents(base_path('modules/FlowBuilder/Validators/FlowValidator.php'));

        $this->assertMatchesRegularExpression(
            "/case 'remove_from_group':\s*\n\s*break;/",
            $source,
            'اشتراط مجموعة يُعيد العقدة الواحدة لكل مجموعة'
        );
    }

    /** واجهة العقدة لا تعرض منتقي مجموعات بعد اليوم. */
    public function test_the_node_ui_no_longer_asks_for_a_group(): void
    {
        $node = file_get_contents(base_path(
            'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/actions/RemoveFromGroup-node.vue'
        ));

        $this->assertStringNotContainsString('group_id', $node);
        $this->assertStringNotContainsString('FormSelect', $node);
        $this->assertStringContainsString('opted out of marketing', $node);
    }
}
