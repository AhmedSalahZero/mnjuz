<?php

namespace Tests\Feature;

use App\Jobs\CreateCampaignLogsJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Template;
use App\Models\User;
use App\Services\CampaignAudienceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * حملة بلا جمهور.
 *
 * من الإنتاج: عميل يسأل لماذا لا تُرسَل حملته. المجموعة التي اختارها فارغة،
 * فلا سجلّات تُنشأ، فلا تتحوّل الحملة إلى ongoing — تبقى «مجدولة» ويُعاد
 * فحصها كل دورة بلا نتيجة ولا رسالة. وسجلّ المنشأة أظهر أن هذا يتكرّر منذ
 * شهور: سبع حملات متوقّفة على المجموعة نفسها.
 *
 * عطلان: الشاشة تقبل ما لا يُرسَل، والمُرسِل يصمت بدل أن يقول لماذا.
 */
class CampaignEmptyAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;
    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create([
            'created_by' => $this->owner->id,
            'metadata' => json_encode(['timezone' => 'Asia/Riyadh']),
        ]);

        $this->template = Template::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'meta_id' => (string) random_int(100000, 999999),
            'name' => 'ksa',
            'category' => 'MARKETING',
            'language' => 'ar',
            'status' => 'APPROVED',
            'metadata' => json_encode(['components' => []]),
            'created_by' => $this->owner->id,
        ]);
    }

    private function group(string $name = 'مجموعة'): ContactGroup
    {
        return ContactGroup::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $name,
            'created_by' => $this->owner->id,
        ]);
    }

    private function contact(?ContactGroup $group = null, array $attributes = []): Contact
    {
        $contact = Contact::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'عميل',
            'phone' => '+2010' . random_int(10000000, 99999999),
            'created_by' => $this->owner->id,
        ], $attributes));

        if ($group) {
            DB::table('contact_contact_group')->insert([
                'contact_id' => $contact->id,
                'contact_group_id' => $group->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $contact;
    }

    private function campaign(?ContactGroup $group, string $status = 'scheduled'): Campaign
    {
        return Campaign::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'ksa',
            'template_id' => $this->template->id,
            'contact_group_id' => $group?->id ?? 0,
            'metadata' => json_encode(['header' => ['parameters' => []], 'body' => ['parameters' => []], 'buttons' => []]),
            'status' => $status,
            'scheduled_at' => now()->subHour(),
            'created_by' => $this->owner->id,
        ]);
    }

    private function runJob(): void
    {
        (new CreateCampaignLogsJob())->handle();
    }

    // ------------------------------------------------- الجمهور

    public function test_an_empty_group_has_no_audience(): void
    {
        $group = $this->group();

        $this->assertTrue(CampaignAudienceService::isEmpty($this->organization->id, $group->id));
        $this->assertSame(0, CampaignAudienceService::count($this->organization->id, $group->id));
    }

    public function test_a_filled_group_has_an_audience(): void
    {
        $group = $this->group();
        $this->contact($group);

        $this->assertFalse(CampaignAudienceService::isEmpty($this->organization->id, $group->id));
        $this->assertSame(1, CampaignAudienceService::count($this->organization->id, $group->id));
    }

    /** المنسحب من التسويق ليس جمهوراً — فمجموعة كلّها منسحبون فارغة. */
    public function test_opted_out_contacts_are_not_an_audience(): void
    {
        $group = $this->group();
        $this->contact($group, ['marketing_opted_out_at' => now()]);

        $this->assertTrue(CampaignAudienceService::isEmpty($this->organization->id, $group->id));
    }

    public function test_deleted_contacts_are_not_an_audience(): void
    {
        $group = $this->group();
        $contact = $this->contact($group);
        Contact::where('id', $contact->id)->update(['deleted_at' => now()]);

        $this->assertTrue(CampaignAudienceService::isEmpty($this->organization->id, $group->id));
    }

    /** و«كل جهات الاتصال» تُقاس بالمنشأة. */
    public function test_all_contacts_means_the_whole_organization(): void
    {
        $this->assertTrue(CampaignAudienceService::isEmpty($this->organization->id, 0));

        $this->contact();

        $this->assertFalse(CampaignAudienceService::isEmpty($this->organization->id, 0));
    }

    // ------------------------------------------------- الحالة بدل الصمت

    /** العطل نفسه: كانت تبقى «مجدولة» إلى الأبد. */
    public function test_a_campaign_without_an_audience_is_marked_failed(): void
    {
        $campaign = $this->campaign($this->group());

        $this->runJob();

        $fresh = $campaign->fresh();

        $this->assertSame('failed', $fresh->status, 'الحملة بقيت صامتة كما كانت');
        $this->assertSame(0, CampaignLog::where('campaign_id', $campaign->id)->count());
    }

    /** ومعها سبب مكتوب — لا عمود له، فيسكن metadata. */
    public function test_the_failure_reason_is_recorded(): void
    {
        $campaign = $this->campaign($this->group());

        $this->runJob();

        $metadata = json_decode($campaign->fresh()->metadata, true);

        $this->assertSame('no_contacts', $metadata['failure_reason']);
        $this->assertNotEmpty($metadata['failed_at']);
    }

    /** ولا يُمحى ما كان في metadata. */
    public function test_marking_failed_keeps_the_existing_metadata(): void
    {
        $campaign = $this->campaign($this->group());

        $this->runJob();

        $metadata = json_decode($campaign->fresh()->metadata, true);

        $this->assertArrayHasKey('header', $metadata, 'محتوى القالب ضاع');
        $this->assertArrayHasKey('buttons', $metadata);
    }

    /** وحملة لها جمهور تمضي كما كانت. */
    public function test_a_campaign_with_an_audience_still_runs(): void
    {
        $group = $this->group();
        $this->contact($group);
        $this->contact($group);
        $campaign = $this->campaign($group);

        $this->runJob();

        // الطابور متزامن في الاختبارات، فقد يكمل الإرسال ويصير completed —
        // والمقصود أنها غادرت «مجدولة» وأُنشئت سجلّاتها.
        $this->assertContains($campaign->fresh()->status, ['ongoing', 'completed']);
        $this->assertSame(2, CampaignLog::where('campaign_id', $campaign->id)->count());
    }

    /** ومجموعة كل من فيها منسحب تُعامَل كالفارغة. */
    public function test_a_group_of_opted_out_contacts_fails_too(): void
    {
        $group = $this->group();
        $this->contact($group, ['marketing_opted_out_at' => now()]);
        $campaign = $this->campaign($group);

        $this->runJob();

        $this->assertSame('failed', $campaign->fresh()->status);
    }

    /** ولا تُمسّ حملة لم يحن موعدها. */
    public function test_a_future_campaign_is_left_alone(): void
    {
        $campaign = $this->campaign($this->group());
        Campaign::where('id', $campaign->id)->update(['scheduled_at' => now()->addDay()]);

        $this->runJob();

        $this->assertSame('scheduled', $campaign->fresh()->status);
    }

    /** ولا حملة جارية أو مكتملة. */
    public function test_only_scheduled_campaigns_are_touched(): void
    {
        $ongoing = $this->campaign($this->group(), 'ongoing');
        $completed = $this->campaign($this->group(), 'completed');

        $this->runJob();

        $this->assertSame('ongoing', $ongoing->fresh()->status);
        $this->assertSame('completed', $completed->fresh()->status);
    }

    /** والفشل يُكتب في السجلّ كي نراه قبل أن يسأل العميل. */
    public function test_the_failure_is_logged(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $this->campaign($this->group());
        $this->runJob();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, 'no audience'))
            ->atLeast()->once();
    }

    // ------------------------------------------------- منع الإنشاء

    private function validate(string $contacts): \Illuminate\Support\MessageBag
    {
        session(['current_organization' => $this->organization->id]);

        $payload = [
            'name' => 'حملة',
            'template' => (string) $this->template->uuid,
            'contacts' => $contacts,
            'skip_schedule' => true,
            'header' => ['format' => 'TEXT', 'parameters' => []],
            'body' => ['parameters' => []],
            'buttons' => [],
        ];

        $request = \App\Http\Requests\StoreCampaign::create('/campaigns', 'POST', $payload);
        $request->setContainer(app())->setRedirector(app(\Illuminate\Routing\Redirector::class));

        $validator = \Illuminate\Support\Facades\Validator::make($payload, $request->rules(), $request->messages());
        $request->withValidator($validator);
        $validator->passes();

        return $validator->errors();
    }

    /** العطل الأوّل: الشاشة كانت تقبل حملة لا تُرسَل. */
    public function test_an_empty_group_is_rejected_at_save(): void
    {
        $group = $this->group();

        $errors = $this->validate((string) $group->uuid);

        $this->assertTrue($errors->has('contacts'));
        $this->assertSame(__('The selected group has no contacts to send to.'), $errors->first('contacts'));
    }

    public function test_a_filled_group_passes(): void
    {
        $group = $this->group();
        $this->contact($group);

        $this->assertFalse($this->validate((string) $group->uuid)->has('contacts'));
    }

    /** و«الكل» بلا جهات اتصال يُردّ كذلك. */
    public function test_all_contacts_with_an_empty_organization_is_rejected(): void
    {
        $this->assertTrue($this->validate('all')->has('contacts'));
    }

    public function test_all_contacts_passes_when_the_organization_has_some(): void
    {
        $this->contact();

        $this->assertFalse($this->validate('all')->has('contacts'));
    }

    /** ومجموعة منشأة أخرى تُردّ. */
    public function test_a_group_of_another_organization_is_rejected(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $group = ContactGroup::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $other->id,
            'name' => 'غريبة',
            'created_by' => $this->owner->id,
        ]);

        $errors = $this->validate((string) $group->uuid);

        $this->assertTrue($errors->has('contacts'));
        $this->assertSame(__('The selected contact group is no longer available.'), $errors->first('contacts'));
    }

    /** والشاشة والمُرسِل يسألان الخدمة نفسها، فلا يختلفان. */
    public function test_the_screen_and_the_sender_ask_the_same_question(): void
    {
        $job = file_get_contents(base_path('app/Jobs/CreateCampaignLogsJob.php'));
        $request = file_get_contents(base_path('app/Http/Requests/StoreCampaign.php'));

        $this->assertStringContainsString('CampaignAudienceService::query(', $job);
        $this->assertStringContainsString('CampaignAudienceService::isEmpty(', $request);
        $this->assertStringNotContainsString(
            "whereNull('marketing_opted_out_at')",
            $job,
            'نسخة ثانية من تعريف الجمهور — وهي ما يجعل الشاشة تقبل ما يرفضه المُرسِل'
        );
    }
}
