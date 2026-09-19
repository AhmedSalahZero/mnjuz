<?php

namespace Tests\Feature\MobileApi;

use App\Models\Organization;
use App\Models\Template;
use Illuminate\Support\Str;

/**
 * القالب المحذوف لا يُستعمل في شيء.
 *
 * العطل: `Template` لم يكن يستعمل SoftDeletes، فكل استعلام كان عليه أن
 * يستثني `deleted_at` بنفسه — ونسيَته تسعة مواضع، منها مسارا الإرسال. بينما
 * تستثنيه شاشة الإعدادات، فتقول «لا قالب مختار» ويُرسَل بالقالب فعلاً.
 *
 * الإصلاح في الموديل لا في المواضع: SoftDeletes تستثنيه من الجميع، والمطابقة
 * مع Meta وحدها تطلبه بـ withTrashed.
 *
 * رُصد على بيانات حقيقية: منشآت إعدادُها يشير إلى قالب حُذف، والرسائل تخرج به.
 */
class DeletedTemplateTest extends MobileApiTestCase
{
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
            'metadata' => json_encode(['components' => []]),
            'created_by' => $this->owner->id,
        ], $attributes));
    }

    /**
     * الحذف كما يفعله النظام: كتابة العمود مباشرةً.
     *
     * Template لا يستعمل SoftDeletes، فـdelete() على الموديل تحذف الصفّ
     * فعلاً — وهو ما لا يحدث في التطبيق.
     */
    private function softDelete(Template $template): void
    {
        Template::where('id', $template->id)->update(['deleted_at' => now()]);
    }

    private function selectAsAuthTemplate(Template $template): void
    {
        Organization::where('id', $this->organization->id)
            ->update(['metadata' => json_encode(['auth_template' => (string) $template->uuid])]);
    }

    // ------------------------------------------------- الإرسال

    /** العطل نفسه: كان يُرسَل بالمحذوف. */
    public function test_a_deleted_auth_template_is_not_sent(): void
    {
        $template = $this->template();
        $this->selectAsAuthTemplate($template);
        $this->softDelete($template);

        $response = $this->postJson('/api/v1/send-auth-template', ['phone' => '+966502486051']);

        $response->assertStatus(400);
        $this->assertStringContainsString('Auth template', $response->json('message'));
    }

    /** ونقطة الإرسال العامّة بالـ uuid كذلك. */
    public function test_a_deleted_template_cannot_be_sent_by_uuid(): void
    {
        $template = $this->template();
        $this->softDelete($template);

        $this->postJson('/api/v1/send-template', [
            'phone' => '+966502486051',
            'template_uuid' => (string) $template->uuid,
        ])->assertStatus(404);
    }

    /**
     * والقالب الحيّ يتجاوز هذا الفحص.
     *
     * لا نُتِمّ الإرسال — يحتاج ربط واتساب — لكن الرسالة تُثبت أن الرفض
     * صار لسببٍ آخر لا لأن القالب «غير مختار».
     */
    public function test_a_live_template_passes_the_lookup(): void
    {
        $template = $this->template();
        $this->selectAsAuthTemplate($template);

        $response = $this->postJson('/api/v1/send-auth-template', ['phone' => '+966502486051']);

        $this->assertStringNotContainsString(
            'Auth template is not set',
            (string) $response->json('message'),
            'القالب الحيّ لا يجوز أن يُعدّ غير مختار'
        );
    }

    public function test_a_live_template_is_found_by_uuid(): void
    {
        $template = $this->template();

        $response = $this->postJson('/api/v1/send-template', [
            'phone' => '+966502486051',
            'template_uuid' => (string) $template->uuid,
        ]);

        $this->assertNotSame(404, $response->status(), 'القالب الحيّ يجب أن يُوجد');
    }

    /** وقالب منشأة أخرى يبقى مرفوضاً كما كان. */
    public function test_a_template_of_another_organization_is_still_refused(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $template = $this->template(['organization_id' => $other->id]);

        $this->postJson('/api/v1/send-template', [
            'phone' => '+966502486051',
            'template_uuid' => (string) $template->uuid,
        ])->assertStatus(404);
    }

    // ------------------------------------------------- الاتّساق

    /**
     * الإعدادات والإرسال يقولان الشيء نفسه.
     *
     * هذا جوهر العطل: كانا يختلفان.
     */
    public function test_settings_and_sending_agree_about_a_deleted_template(): void
    {
        $template = $this->template();
        $this->selectAsAuthTemplate($template);
        $this->softDelete($template);

        $this->getJson('/api/v1/settings/general')
            ->assertOk()
            ->assertJsonPath('data.auth_template', null);

        $this->postJson('/api/v1/send-auth-template', ['phone' => '+966502486051'])
            ->assertStatus(400);
    }

    /** ولا يُعرض المحذوف بين خيارات الاختيار. */
    public function test_a_deleted_template_is_not_offered_as_an_option(): void
    {
        $this->softDelete($this->template());

        $this->getJson('/api/v1/settings/general')
            ->assertOk()
            ->assertJsonPath('data.auth_templates', []);
    }

    /** ولا يُقبل اختياره من جديد. */
    public function test_a_deleted_template_cannot_be_selected(): void
    {
        $template = $this->template();
        $this->softDelete($template);

        $this->postJson('/api/v1/settings/general', ['auth_template' => (string) $template->uuid])
            ->assertStatus(400);
    }

    // ------------------------------------------------- «اختر قالباً»

    /**
     * اختيارٌ ضاع يُميَّز عن «لم يُختَر قطّ».
     *
     * الحقلان يعودان null في الحالتين، والفرق مهمّ: الثانية تعني أن رسالة
     * التحقّق توقّفت عند العميل وهو لا يدري.
     */
    public function test_a_deleted_selection_is_reported_as_missing(): void
    {
        $template = $this->template();
        $this->selectAsAuthTemplate($template);
        $this->softDelete($template);

        $this->getJson('/api/v1/settings/general')
            ->assertOk()
            ->assertJsonPath('data.auth_template', null)
            ->assertJsonPath('data.auth_template_missing', true);
    }

    /** ومن لم يختر قالباً قطّ ليس في حالة عطل. */
    public function test_never_choosing_a_template_is_not_missing(): void
    {
        $this->getJson('/api/v1/settings/general')
            ->assertOk()
            ->assertJsonPath('data.auth_template', null)
            ->assertJsonPath('data.auth_template_missing', false);
    }

    /** والاختيار السليم كذلك. */
    public function test_a_live_selection_is_not_missing(): void
    {
        $template = $this->template();
        $this->selectAsAuthTemplate($template);

        $this->getJson('/api/v1/settings/general')
            ->assertOk()
            ->assertJsonPath('data.auth_template.uuid', (string) $template->uuid)
            ->assertJsonPath('data.auth_template_missing', false);
    }

    /** واختيار بديل يُطفئ التنبيه. */
    public function test_choosing_a_replacement_clears_the_warning(): void
    {
        $old = $this->template(['name' => 'old']);
        $this->selectAsAuthTemplate($old);
        $this->softDelete($old);

        $replacement = $this->template(['name' => 'new']);

        $this->postJson('/api/v1/settings/general', ['auth_template' => (string) $replacement->uuid])
            ->assertOk()
            ->assertJsonPath('data.auth_template_missing', false)
            ->assertJsonPath('data.auth_template.name', 'new');
    }

    // ------------------------------------------------- الموديل نفسه

    /**
     * الاستثناء صار في الموديل لا في كل استعلام.
     *
     * تسعة مواضع كانت تنساه — ومنها الإرسال. والمواضع التي تحتاج الصفّ
     * المحذوف تطلبه صراحةً بـ withTrashed.
     */
    public function test_the_model_hides_deleted_rows_everywhere(): void
    {
        $template = $this->template();
        $this->softDelete($template);

        $this->assertNull(Template::where('uuid', $template->uuid)->first(), 'الاستعلام العادي يجب ألّا يجده');
        $this->assertNotNull(
            Template::withTrashed()->where('uuid', $template->uuid)->first(),
            'withTrashed يجب أن تجده — المزامنة مع Meta تحتاجه'
        );
    }

    /** ومطابقة Meta تجد الصفّ المحذوف فلا تُنشئ له نسخة ثانية. */
    public function test_meta_reconciliation_still_finds_deleted_rows(): void
    {
        foreach ([
            'app/Jobs/ProcessTemplateStatusJob.php',
            'app/Services/WhatsappService.php',
        ] as $path) {
            $this->assertStringContainsString(
                'Template::withTrashed()',
                file_get_contents(base_path($path)),
                $path . ': المطابقة بـ meta_id تحتاج الصفّ المحذوف'
            );
        }
    }

    /** وحملة قالبها حُذف تقف برسالة مفهومة لا بخطأ فادح. */
    public function test_a_campaign_with_a_deleted_template_fails_clearly(): void
    {
        $source = file_get_contents(base_path('app/Traits/TemplateTrait.php'));

        $this->assertStringContainsString('if (!$campaignTemplate)', $source);
        $this->assertStringContainsString('cannot be sent', $source);
    }

    /** والموديل يستعمل SoftDeletes — وهو أصل الإصلاح. */
    public function test_the_model_uses_soft_deletes(): void
    {
        $this->assertContains(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive(Template::class),
            'بدونها يعود كل استعلام مسؤولاً عن استثناء المحذوف بنفسه'
        );
    }
}
