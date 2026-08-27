<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * معاينة القالب في صفحة الحملة.
 *
 * العلّة الأصلية: WhatsappTemplate يستقبل placeholder بقيمة افتراضية true،
 * وCampaignForm لا يمرّرها إطلاقاً — فالمعاينة ترسم العنصر النائب مهما
 * اختار العميل. ومعها كانت المعاينة تقرأ value، وهو عند الرفع كائن File لا
 * رابطاً، فحتى تمرير الخاصية وحده ما كان ليكفي.
 *
 * ما يُحرَس هنا: أن المنطق يبقى في وحدة قابلة للاختبار، وأن المكوّنين
 * يستعملانها فعلاً — فالسهو في التمرير هو ما أنتج العطل، لا المنطق.
 */
class CampaignMediaPreviewTest extends TestCase
{
    private function nodeAvailable(): bool
    {
        exec('node --version 2>/dev/null', $output, $status);

        return $status === 0;
    }

    public function test_the_preview_source_logic_holds_for_every_parameter_shape(): void
    {
        if (!$this->nodeAvailable()) {
            $this->markTestSkipped('node غير متاح في هذه البيئة');
        }

        $script = base_path('tests/js/template-media-preview.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    public function test_the_logic_stays_in_its_own_testable_module(): void
    {
        $this->assertFileExists(base_path('resources/js/Composables/templateMediaPreview.js'));
    }

    /**
     * السهو الذي أنتج العطل: استدعاء المعاينة بلا placeholder.
     */
    public function test_the_campaign_form_passes_the_placeholder_flag(): void
    {
        $form = file_get_contents(base_path('resources/js/Components/CampaignForm.vue'));

        $this->assertStringContainsString('chosenHeaderPreviewSource', $form, 'النموذج لا يستورد منطق المعاينة');
        $this->assertMatchesRegularExpression(
            '/<WhatsappTemplate[^>]*:placeholder=/s',
            $form,
            'المعاينة تُستدعى بلا placeholder فتبقى على العنصر النائب دائماً'
        );
    }

    /** المعاينة تقرأ المصدر الموحّد لا value الذي قد يكون كائن File. */
    public function test_the_preview_component_reads_the_shared_source(): void
    {
        $preview = file_get_contents(base_path('resources/js/Components/WhatsappTemplate.vue'));

        $this->assertStringContainsString('mediaPreviewSource', $preview);
        $this->assertStringNotContainsString(
            'parameters.header.parameters[0].value',
            $preview,
            'ما زالت المعاينة تقرأ value مباشرةً'
        );
    }

    /**
     * اختيار الملف السابق يُرسل المعرّف: المطابقة بالمسار النصّي كانت
     * تُسقط اختياراً صحيحاً عند أيّ اختلاف حرف في الرابط.
     */
    public function test_history_selection_sends_the_uuid_and_previews_the_path(): void
    {
        $form = file_get_contents(base_path('resources/js/Components/CampaignForm.vue'));

        $this->assertMatchesRegularExpression(
            '/selection = \'history\'\s*\n\s*form\.header\.parameters\[0\]\.value = item\.uuid/',
            $form,
            'الاختيار ما زال يرسل المسار بدل المعرّف'
        );
        $this->assertStringContainsString(
            'form.header.parameters[0].url = item.path',
            $form,
            'المعاينة تحتاج المسار في url'
        );
    }

    /**
     * صورة مثال القالب (example.header_handle) رابط كامل من واتساب. عرضها في
     * نموذج الإنشاء يُظهر ترويسةً جاهزة قبل أن يختار العميل شيئاً، ثم يُردّ
     * عليه بأن الحقل مطلوب — تناقض أمام عينيه.
     */
    public function test_the_form_previews_only_what_the_user_picked(): void
    {
        $form = file_get_contents(base_path('resources/js/Components/CampaignForm.vue'));

        $this->assertStringContainsString(
            'chosenHeaderPreviewSource(form.header)',
            $form,
            'المعاينة تعرض مثال القالب قبل الاختيار'
        );

        $composable = file_get_contents(base_path('resources/js/Composables/templateMediaPreview.js'));

        $this->assertStringContainsString("CHOSEN_SELECTIONS = ['upload', 'history']", $composable);
        $this->assertStringNotContainsString(
            "CHOSEN_SELECTIONS = ['upload', 'history', 'default']",
            $composable,
            'default تعني مثال القالب لا اختيار العميل'
        );
    }

    /** رسالة الخادم أوضح من «حدث خطأ ما» — وهي ما رآه العميل عند الحذف. */
    public function test_delete_failures_surface_the_server_message(): void
    {
        $form = file_get_contents(base_path('resources/js/Components/CampaignForm.vue'));

        $this->assertStringContainsString('error?.response?.data?.message', $form);
    }

    /** الصفّ كلّه زرّ الاختيار، ومعه صورة مصغّرة وعلامة «محدَّد». */
    public function test_the_history_row_shows_what_is_selected(): void
    {
        $form = file_get_contents(base_path('resources/js/Components/CampaignForm.vue'));

        $this->assertStringContainsString('$t(\'Selected\')', $form, 'لا علامة تدلّ على الملف المحدَّد');
        $this->assertStringContainsString('$t(\'Click to use this file\')', $form, 'لا دعوة واضحة للضغط');
        $this->assertStringContainsString(':src="item.path"', $form, 'لا صورة مصغّرة للملف السابق');
    }
}
