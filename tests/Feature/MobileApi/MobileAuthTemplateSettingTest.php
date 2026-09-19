<?php

namespace Tests\Feature\MobileApi;

use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Template;
use Illuminate\Support\Str;

/**
 * قوائم الاختيار الناقصة في الإعدادات العامّة، وقالب المصادقة.
 *
 * النقطة كانت تقبل تعديل `campaigns.failed_campaign_group` وتُرجع المختار
 * منها ولا تُرجع البدائل — فالتطبيق يعرف ما هو مختار ولا يستطيع رسم قائمة
 * الاختيار. وقالب المصادقة — الذي يُرسل به رمز التحقق — كان غائباً كلّه:
 * لا قراءة ولا كتابة.
 *
 * والمتغيّرات تُحفظ ومعها uuid القالب الذي بُنيت له، ويشترط المُرسِل تطابقهما
 * (ApiController::sendAuthTemplate) — فمتغيّرات قالبٍ قديم تُهمَل صامتةً.
 */
class MobileAuthTemplateSettingTest extends MobileApiTestCase
{
    /** بنية قالب حقيقية كما تحفظها Meta. */
    private const COMPONENTS = [
        ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'هذه رسالة من ليدز'],
        ['type' => 'BODY', 'text' => 'أهلاً {{1}}، رمز التحقق هو {{2}}'],
        ['type' => 'FOOTER', 'text' => 'نورتِ'],
        ['type' => 'BUTTONS', 'buttons' => [
            ['type' => 'URL', 'text' => 'موقعنا', 'url' => 'https://ladyes.co/'],
        ]],
    ];

    private function template(array $attributes = []): Template
    {
        return Template::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'meta_id' => (string) random_int(100000, 999999),
            'name' => 'startchat',
            'category' => 'AUTHENTICATION',
            'language' => 'ar',
            'status' => 'APPROVED',
            'metadata' => json_encode(['components' => self::COMPONENTS]),
            'created_by' => $this->owner->id,
        ], $attributes));
    }

    /** منشأة أخرى حقيقية — القوالب والمجموعات لها مفاتيح أجنبية. */
    private function otherOrganization(): Organization
    {
        return Organization::factory()->create(['created_by' => $this->owner->id]);
    }

    private function group(string $name, ?int $organizationId = null): ContactGroup
    {
        return ContactGroup::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organizationId ?? $this->organization->id,
            'name' => $name,
            'created_by' => $this->owner->id,
        ]);
    }

    private function general(): array
    {
        return $this->getJson('/api/v1/settings/general')->assertOk()->json('data');
    }

    private function storedMetadata(): array
    {
        return json_decode(Organization::find($this->organization->id)->metadata, true) ?: [];
    }

    private function setMetadata(array $metadata): void
    {
        Organization::where('id', $this->organization->id)->update(['metadata' => json_encode($metadata)]);
    }

    // ------------------------------------------------- مجموعات جهات الاتصال

    public function test_the_contact_groups_are_returned(): void
    {
        $group = $this->group('عملاء مميزون');

        $groups = $this->general()['contact_groups'];

        $this->assertCount(1, $groups);
        $this->assertSame((string) $group->uuid, $groups[0]['uuid']);
        $this->assertSame('عملاء مميزون', $groups[0]['name']);
    }

    public function test_the_groups_are_sorted_by_name(): void
    {
        $this->group('ب');
        $this->group('أ');

        $this->assertSame(['أ', 'ب'], array_column($this->general()['contact_groups'], 'name'));
    }

    public function test_groups_of_another_organization_are_hidden(): void
    {
        $this->group('غريبة', $this->otherOrganization()->id);

        $this->assertSame([], $this->general()['contact_groups']);
    }

    public function test_a_deleted_group_is_hidden(): void
    {
        $this->group('محذوفة')->delete();

        $this->assertSame([], $this->general()['contact_groups']);
    }

    /** والمجموعة المختارة للحملات الفاشلة موجودة بين البدائل. */
    public function test_the_selected_failed_group_is_among_the_options(): void
    {
        $group = $this->group('الفاشلة');
        $this->setMetadata(['campaigns' => ['failed_campaign_group' => (string) $group->uuid]]);

        $data = $this->general();

        $this->assertContains(
            $data['campaigns']['failed_campaign_group'],
            array_column($data['contact_groups'], 'uuid'),
            'المختار ليس ضمن ما يُعرض على العميل'
        );
    }

    public function test_an_agent_sees_the_groups_too(): void
    {
        $this->group('عامّة');
        $this->actAs($this->member('agent'));

        $this->assertCount(1, $this->general()['contact_groups']);
    }

    // ------------------------------------------------- قائمة القوالب

    public function test_only_approved_templates_are_offered(): void
    {
        $approved = $this->template(['name' => 'approved']);
        $this->template(['name' => 'pending', 'status' => 'PENDING']);
        $this->template(['name' => 'rejected', 'status' => 'REJECTED']);

        $offered = $this->general()['auth_templates'];

        $this->assertCount(1, $offered);
        $this->assertSame((string) $approved->uuid, $offered[0]['uuid']);
        $this->assertSame('ar', $offered[0]['language']);
    }

    public function test_templates_of_another_organization_are_not_offered(): void
    {
        $this->template(['organization_id' => $this->otherOrganization()->id]);

        $this->assertSame([], $this->general()['auth_templates']);
    }

    public function test_a_deleted_template_is_not_offered(): void
    {
        $this->template()->delete();

        $this->assertSame([], $this->general()['auth_templates']);
    }

    public function test_the_offered_templates_are_sorted_by_name(): void
    {
        $this->template(['name' => 'zeta']);
        $this->template(['name' => 'alpha']);

        $this->assertSame(['alpha', 'zeta'], array_column($this->general()['auth_templates'], 'name'));
    }

    // ------------------------------------------------- قراءة القالب المختار

    public function test_no_auth_template_is_null(): void
    {
        $this->assertNull($this->general()['auth_template']);
    }

    public function test_the_selected_template_is_returned(): void
    {
        $template = $this->template();
        $this->setMetadata(['auth_template' => (string) $template->uuid]);

        $selected = $this->general()['auth_template'];

        $this->assertSame((string) $template->uuid, $selected['uuid']);
        $this->assertSame('startchat', $selected['name']);
        $this->assertSame('ar', $selected['language']);
        $this->assertSame('APPROVED', $selected['status']);
        $this->assertNull($selected['parameters']);
    }

    /** قالبٌ حُذف بعد اختياره: لا نُرجع معرّفاً لا يقابله شيء. */
    public function test_a_stored_uuid_of_a_missing_template_is_null(): void
    {
        $this->setMetadata(['auth_template' => (string) Str::uuid()]);

        $this->assertNull($this->general()['auth_template']);
    }

    public function test_matching_parameters_are_returned(): void
    {
        $template = $this->template();
        $parameters = ['template' => (string) $template->uuid, 'body' => ['parameters' => [['type' => 'text', 'value' => '123']]]];
        $this->setMetadata(['auth_template' => (string) $template->uuid, 'auth_template_parameters' => $parameters]);

        $this->assertEquals($parameters, $this->general()['auth_template']['parameters']);
    }

    /** متغيّرات قالبٍ آخر يُهملها المُرسِل — فلا نعرضها كأنها فعّالة. */
    public function test_parameters_of_a_different_template_are_not_returned(): void
    {
        $template = $this->template();
        $this->setMetadata([
            'auth_template' => (string) $template->uuid,
            'auth_template_parameters' => ['template' => (string) Str::uuid()],
        ]);

        $this->assertNull($this->general()['auth_template']['parameters']);
    }

    // ------------------------------------------------- بنية القالب

    /**
     * المعاينة تحتاج نصّ القالب لا متغيّراته.
     *
     * `parameters` ما حفظه المستخدم، وهو لا يكفي لرسم فقاعة الواتساب: لا
     * عنوان ولا متن ولا تذييل ولا أزرار. فنُرجع مكوّنات القالب المختار معه.
     */
    public function test_the_selected_template_carries_its_components(): void
    {
        $template = $this->template();
        $this->setMetadata(['auth_template' => (string) $template->uuid]);

        $components = $this->general()['auth_template']['components'];

        $this->assertCount(4, $components);
        $this->assertSame(self::COMPONENTS, $components);
    }

    /** كل جزء من المعاينة موجود: العنوان والمتن والتذييل والأزرار. */
    public function test_the_components_carry_every_part_of_the_preview(): void
    {
        $template = $this->template();
        $this->setMetadata(['auth_template' => (string) $template->uuid]);

        $components = collect($this->general()['auth_template']['components'])->keyBy('type');

        $this->assertSame('هذه رسالة من ليدز', $components['HEADER']['text']);
        $this->assertSame('أهلاً {{1}}، رمز التحقق هو {{2}}', $components['BODY']['text']);
        $this->assertSame('نورتِ', $components['FOOTER']['text']);
        $this->assertSame('موقعنا', $components['BUTTONS']['buttons'][0]['text']);
    }

    /** metadata بلا مفتاح components: مصفوفة فارغة لا null. */
    public function test_a_template_without_components_returns_an_empty_array(): void
    {
        $template = $this->template(['metadata' => json_encode(['name' => 'x'])]);
        $this->setMetadata(['auth_template' => (string) $template->uuid]);

        $this->assertSame([], $this->general()['auth_template']['components']);
    }

    /** وmetadata تالفة لا تُسقط الردّ. */
    public function test_broken_metadata_does_not_break_the_response(): void
    {
        $template = $this->template(['metadata' => 'ليست JSON']);
        $this->setMetadata(['auth_template' => (string) $template->uuid]);

        $this->assertSame([], $this->general()['auth_template']['components']);
    }

    /** والقائمة تبقى خفيفة: لا مكوّنات مع كل قالب. */
    public function test_the_options_list_stays_light(): void
    {
        $this->template();

        foreach ($this->general()['auth_templates'] as $option) {
            $this->assertSame(['uuid', 'name', 'language'], array_keys($option));
        }
    }

    /** وبعد الحفظ تصل المكوّنات مباشرةً بلا استدعاء آخر. */
    public function test_components_arrive_right_after_saving_the_template(): void
    {
        $template = $this->template();

        $this->postJson('/api/v1/settings/general', ['auth_template' => (string) $template->uuid])
            ->assertOk()
            ->assertJsonPath('data.auth_template.components', self::COMPONENTS);
    }

    // ------------------------------------------------- الكتابة

    public function test_it_saves_the_auth_template(): void
    {
        $template = $this->template();

        $this->postJson('/api/v1/settings/general', ['auth_template' => (string) $template->uuid])
            ->assertOk()
            ->assertJsonPath('data.auth_template.uuid', (string) $template->uuid);

        $this->assertSame((string) $template->uuid, $this->storedMetadata()['auth_template']);
    }

    public function test_a_template_that_is_not_approved_is_rejected(): void
    {
        $template = $this->template(['status' => 'PENDING']);

        $response = $this->postJson('/api/v1/settings/general', ['auth_template' => (string) $template->uuid]);

        $response->assertStatus(400);
        $this->assertArrayHasKey('auth_template', $response->json('errors'));
        $this->assertArrayNotHasKey('auth_template', $this->storedMetadata());
    }

    public function test_a_template_of_another_organization_is_rejected(): void
    {
        $template = $this->template(['organization_id' => $this->otherOrganization()->id]);

        $this->postJson('/api/v1/settings/general', ['auth_template' => (string) $template->uuid])
            ->assertStatus(400);
    }

    public function test_an_unknown_uuid_is_rejected(): void
    {
        $this->postJson('/api/v1/settings/general', ['auth_template' => (string) Str::uuid()])
            ->assertStatus(400);
    }

    public function test_null_clears_the_selection(): void
    {
        $template = $this->template();
        $this->setMetadata([
            'auth_template' => (string) $template->uuid,
            'auth_template_parameters' => ['template' => (string) $template->uuid],
        ]);

        $this->postJson('/api/v1/settings/general', ['auth_template' => null])->assertOk();

        $metadata = $this->storedMetadata();

        $this->assertArrayNotHasKey('auth_template', $metadata);
        $this->assertArrayNotHasKey('auth_template_parameters', $metadata, 'المتغيّرات بقيت بلا قالب');
    }

    public function test_an_empty_string_clears_the_selection_too(): void
    {
        $template = $this->template();
        $this->setMetadata(['auth_template' => (string) $template->uuid]);

        $this->postJson('/api/v1/settings/general', ['auth_template' => ''])->assertOk();

        $this->assertArrayNotHasKey('auth_template', $this->storedMetadata());
    }

    /** تبديل القالب يُسقط متغيّرات سابقه — وإلا بقيت تُوهم أنها فعّالة. */
    public function test_switching_templates_drops_the_old_variables(): void
    {
        $old = $this->template(['name' => 'old']);
        $new = $this->template(['name' => 'new']);
        $this->setMetadata([
            'auth_template' => (string) $old->uuid,
            'auth_template_parameters' => ['template' => (string) $old->uuid],
        ]);

        $this->postJson('/api/v1/settings/general', ['auth_template' => (string) $new->uuid])->assertOk();

        $metadata = $this->storedMetadata();

        $this->assertSame((string) $new->uuid, $metadata['auth_template']);
        $this->assertArrayNotHasKey('auth_template_parameters', $metadata);
    }

    /** وإعادة حفظ القالب نفسه لا تمسّ متغيّراته. */
    public function test_resaving_the_same_template_keeps_its_variables(): void
    {
        $template = $this->template();
        $parameters = ['template' => (string) $template->uuid, 'body' => ['parameters' => []]];
        $this->setMetadata(['auth_template' => (string) $template->uuid, 'auth_template_parameters' => $parameters]);

        $this->postJson('/api/v1/settings/general', ['auth_template' => (string) $template->uuid])->assertOk();

        $this->assertEquals($parameters, $this->storedMetadata()['auth_template_parameters']);
    }

    public function test_it_saves_the_template_and_its_variables_together(): void
    {
        $template = $this->template();
        $parameters = [
            'template' => (string) $template->uuid,
            'body' => ['parameters' => [['type' => 'text', 'selection' => 'static', 'value' => '1234']]],
            'buttons' => [],
        ];

        $this->postJson('/api/v1/settings/general', [
            'auth_template' => (string) $template->uuid,
            'auth_template_parameters' => $parameters,
        ])->assertOk();

        $this->assertEquals($parameters, $this->storedMetadata()['auth_template_parameters']);
        $this->assertEquals($parameters, $this->general()['auth_template']['parameters']);
    }

    /** متغيّرات لقالبٍ آخر تُردّ برسالة بدل أن تُحفَظ ثم تُهمَل. */
    public function test_variables_of_a_different_template_are_rejected(): void
    {
        $template = $this->template();

        $response = $this->postJson('/api/v1/settings/general', [
            'auth_template' => (string) $template->uuid,
            'auth_template_parameters' => ['template' => (string) Str::uuid()],
        ]);

        $response->assertStatus(400);
        $this->assertArrayHasKey('auth_template_parameters', $response->json('errors'));
        $this->assertArrayNotHasKey('auth_template_parameters', $this->storedMetadata());
    }

    /** وإرسال المتغيّرات وحدها يُقاس على القالب المحفوظ. */
    public function test_variables_alone_are_checked_against_the_stored_template(): void
    {
        $template = $this->template();
        $this->setMetadata(['auth_template' => (string) $template->uuid]);

        $this->postJson('/api/v1/settings/general', [
            'auth_template_parameters' => ['template' => (string) $template->uuid, 'body' => ['parameters' => []]],
        ])->assertOk();

        $this->assertArrayHasKey('auth_template_parameters', $this->storedMetadata());

        $this->postJson('/api/v1/settings/general', [
            'auth_template_parameters' => ['template' => (string) Str::uuid()],
        ])->assertStatus(400);
    }

    public function test_empty_variables_clear_them(): void
    {
        $template = $this->template();
        $this->setMetadata([
            'auth_template' => (string) $template->uuid,
            'auth_template_parameters' => ['template' => (string) $template->uuid],
        ]);

        $this->postJson('/api/v1/settings/general', ['auth_template_parameters' => []])->assertOk();

        $metadata = $this->storedMetadata();

        $this->assertArrayNotHasKey('auth_template_parameters', $metadata);
        $this->assertSame((string) $template->uuid, $metadata['auth_template'], 'القالب نفسه لا يُمسّ');
    }

    public function test_an_agent_cannot_change_the_auth_template(): void
    {
        $template = $this->template();
        $this->actAs($this->member('agent'));

        $this->postJson('/api/v1/settings/general', ['auth_template' => (string) $template->uuid])
            ->assertStatus(403);

        $this->assertArrayNotHasKey('auth_template', $this->storedMetadata());
    }

    /** وحفظ القالب لا يمحو بقيّة الإعدادات. */
    public function test_saving_the_template_does_not_wipe_other_settings(): void
    {
        $template = $this->template();
        $this->setMetadata([
            'timezone' => 'Asia/Riyadh',
            'whatsapp' => ['access_token' => 'secret'],
            'working_hours' => [['day' => 1, 'open' => '09:00', 'close' => '17:00']],
        ]);

        $this->postJson('/api/v1/settings/general', ['auth_template' => (string) $template->uuid])->assertOk();

        $metadata = $this->storedMetadata();

        $this->assertSame('secret', $metadata['whatsapp']['access_token']);
        $this->assertCount(1, $metadata['working_hours']);
        $this->assertSame('Asia/Riyadh', $metadata['timezone']);
    }

    /**
     * ما نحفظه هو ما يقرأه المُرسِل.
     *
     * send-auth-template يبحث عن metadata['auth_template'] قالباً لهذه
     * المنشأة، ويشترط `parameters.template` مطابقاً له. فهذا يثبت أن الحفظ
     * من التطبيق يُنتج حالةً يقبلها المُرسِل.
     */
    public function test_what_we_save_is_what_the_sender_expects(): void
    {
        $template = $this->template();
        $parameters = ['template' => (string) $template->uuid, 'body' => ['parameters' => []]];

        $this->postJson('/api/v1/settings/general', [
            'auth_template' => (string) $template->uuid,
            'auth_template_parameters' => $parameters,
        ])->assertOk();

        $metadata = $this->storedMetadata();

        $found = Template::where('uuid', $metadata['auth_template'])
            ->where('organization_id', $this->organization->id)
            ->first();

        $this->assertNotNull($found, 'المُرسِل لن يجد القالب');
        $this->assertSame($metadata['auth_template'], $metadata['auth_template_parameters']['template']);
    }
}
