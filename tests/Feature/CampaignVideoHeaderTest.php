<?php

namespace Tests\Feature;

use App\Http\Requests\StoreCampaign;
use App\Traits\TemplateTrait;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * ترويسة الوسائط في الحملة.
 *
 * العطل كما وصل من العميل: الحملة ترسل الفيديو القديم لا الذي اختاره.
 *
 * والسبب سلسلة: قالبٌ أُنشئ في Meta بلا example يصل بلا header_handle، فتبقى
 * `header.parameters` فارغة. عندها لا يرسم النموذج زرّ اختيار الفيديو، ولا
 * تُطبَّق قاعدة تحقّق واحدة — لأن الشرط كان «إن وُجدت معاملات» — فتُحفظ
 * الحملة بلا وسائط، ويخرج القالب إلى Meta بلا مكوّن ترويسة، فتملؤه Meta
 * بمثاله المرفق: الفيديو الذي رُفع يوم أُنشئ القالب.
 */
class CampaignVideoHeaderTest extends TestCase
{
    use TemplateTrait;

    /** الأخطاء على حقل الوسائط وحده — لا على حدود الاشتراك وغيرها. */
    private function mediaErrors(array $header): array
    {
        $payload = [
            'name' => 'حملة',
            'template' => 'uuid-template',
            'contacts' => 'uuid-group',
            'skip_schedule' => true,
            'header' => $header,
            'body' => ['parameters' => []],
            'buttons' => [],
        ];

        $request = StoreCampaign::create('/campaigns', 'POST', $payload);

        return Validator::make($payload, $request->rules(), $request->messages())
            ->errors()
            ->get('header.parameters.0.value');
    }

    // ------------------------------------------------ التحقّق

    /** الحالة المكسورة: ترويسة VIDEO بلا معاملات كانت تمرّ بلا فيديو. */
    public function test_a_video_header_without_parameters_is_rejected(): void
    {
        $errors = $this->mediaErrors(['format' => 'VIDEO', 'parameters' => []]);

        $this->assertNotEmpty($errors, 'حملة فيديو بلا فيديو يجب ألّا تُحفظ');
        $this->assertStringContainsString('header video', $errors[0]);
    }

    /** ولا حتى حين يغيب مفتاح parameters كلّه. */
    public function test_a_video_header_without_the_parameters_key_is_rejected(): void
    {
        $this->assertNotEmpty($this->mediaErrors(['format' => 'VIDEO']));
    }

    public function test_an_image_header_without_parameters_is_rejected(): void
    {
        $this->assertNotEmpty($this->mediaErrors(['format' => 'IMAGE', 'parameters' => []]));
    }

    public function test_a_document_header_without_parameters_is_rejected(): void
    {
        $this->assertNotEmpty($this->mediaErrors(['format' => 'DOCUMENT', 'parameters' => []]));
    }

    /** والرسالة تدلّ على ما يفعله العميل، لا «هذا الحقل مطلوب». */
    public function test_the_message_tells_the_customer_what_to_do(): void
    {
        $errors = $this->mediaErrors(['format' => 'VIDEO', 'parameters' => []]);

        $this->assertStringContainsString('upload a new one', $errors[0]);
        $this->assertStringContainsString('previously used file', $errors[0]);
    }

    /** اختيار ملف سابق يمرّ كما كان. */
    public function test_choosing_a_previously_used_file_still_passes(): void
    {
        $this->assertEmpty($this->mediaErrors([
            'format' => 'VIDEO',
            'parameters' => [['type' => 'VIDEO', 'selection' => 'history', 'value' => 'a-uuid']],
        ]));
    }

    /** وحملة محفوظة برابط كامل تمرّ كذلك. */
    public function test_a_saved_campaign_url_still_passes(): void
    {
        $this->assertEmpty($this->mediaErrors([
            'format' => 'VIDEO',
            'parameters' => [[
                'type' => 'VIDEO',
                'selection' => 'default',
                'value' => 'https://mnjzchat.s3.amazonaws.com/uploads/a.mp4',
            ]],
        ]));
    }

    /** والترويسة النصّية لم يمسّها شيء. */
    public function test_a_text_header_is_unaffected(): void
    {
        $this->assertEmpty($this->mediaErrors([
            'format' => 'TEXT',
            'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'عرض اليوم']],
        ]));

        $this->assertNotEmpty($this->mediaErrors([
            'format' => 'TEXT',
            'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => null]],
        ]));
    }

    /** وقالب بلا ترويسة إطلاقاً لا يُطالَب بشيء. */
    public function test_a_template_without_a_header_asks_for_nothing(): void
    {
        $this->assertEmpty($this->mediaErrors(['format' => null, 'parameters' => []]));
    }

    // ------------------------------------------------ بناء الطلب إلى Meta

    private function build(array $metadata): array
    {
        return $this->buildTemplate('promo', 'ar', json_decode(json_encode($metadata)), (object) ['phone' => '+966500000000']);
    }

    /** حملة قديمة محفوظة بلا مفتاح header لا تُسقط الإرسال. */
    public function test_a_campaign_saved_without_a_header_does_not_break(): void
    {
        $template = $this->build(['body' => ['parameters' => []], 'buttons' => []]);

        $this->assertSame('promo', $template['name']);
        $this->assertSame([], $template['components']);
    }

    /** والفيديو المختار يصل Meta في مكوّن الترويسة. */
    public function test_the_chosen_video_reaches_the_header_component(): void
    {
        $template = $this->build([
            'header' => [
                'format' => 'VIDEO',
                'parameters' => [['type' => 'VIDEO', 'selection' => 'upload', 'value' => 'https://s3/newest.mp4']],
            ],
            'body' => ['parameters' => []],
            'buttons' => [],
        ]);

        $this->assertCount(1, $template['components']);
        $this->assertSame('header', $template['components'][0]['type']);
        $this->assertSame('https://s3/newest.mp4', $template['components'][0]['parameters'][0]['video']['link']);
    }

    /** ترويسة وسائط بلا معاملات لا تُرسِل مكوّناً فارغاً تملؤه Meta بمثالها. */
    public function test_an_empty_media_header_sends_no_component(): void
    {
        $template = $this->build([
            'header' => ['format' => 'VIDEO', 'parameters' => []],
            'body' => ['parameters' => []],
            'buttons' => [],
        ]);

        $this->assertSame([], $template['components']);
    }

    // ------------------------------------------------ حراسة على المصدر

    /** الصيغة تُكتب في metadata دائماً، فلا تصل حملة بلا مفتاح header. */
    public function test_the_service_always_records_the_header_format(): void
    {
        $source = file_get_contents(base_path('app/Services/CampaignService.php'));

        $this->assertMatchesRegularExpression(
            "/\\\$metadata\\['header'\\]\\['format'\\] = \\\$header\\['format'\\];\\s*\\n\\s*\\\$metadata\\['header'\\]\\['parameters'\\] = \\[\\];\\s*\\n\\s*\\n\\s*if \\(\\\$request->header\\['parameters'\\]\\)/",
            $source,
            'الصيغة يجب أن تُسجَّل قبل فحص وجود المعاملات لا بعده'
        );
    }
}
