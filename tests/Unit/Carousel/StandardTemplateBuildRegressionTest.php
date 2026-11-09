<?php

namespace Tests\Unit\Carousel;

use App\Traits\TemplateTrait;
use PHPUnit\Framework\TestCase;

/**
 * لقطة لما يبنيه buildTemplate للقوالب العادية، كُتبت على الكود قبل إضافة
 * الـ carousel ومرّت عليه.
 *
 * الـ carousel فرعٌ مضاف لا يُفعَّل إلا بمفتاح carousel في metadata الحملة؛
 * وهذه اللقطات تشهد أن كل ما عداه يخرج إلى Meta كما كان حرفاً بحرف.
 */
class StandardTemplateBuildRegressionTest extends TestCase
{
    use TemplateTrait;

    private function contact(): object
    {
        return (object) [
            'first_name' => 'Sara',
            'last_name' => 'Ali',
            'email' => 'sara@example.com',
            'phone' => '+966500000001',
        ];
    }

    private function build(array $metadata): array
    {
        return $this->buildTemplate('promo', 'ar', json_decode(json_encode($metadata)), $this->contact());
    }

    public function test_text_header_body_quick_reply_and_url(): void
    {
        $template = $this->build([
            'header' => [
                'format' => 'TEXT',
                'text' => 'Hi {{1}}',
                'parameters' => [['type' => 'text', 'selection' => 'dynamic', 'value' => 'first name']],
            ],
            'body' => [
                'text' => 'Code {{1}} for {{2}}',
                'parameters' => [
                    ['type' => 'text', 'selection' => 'static', 'value' => 'SAVE10'],
                    ['type' => 'text', 'selection' => 'dynamic', 'value' => 'name'],
                ],
            ],
            'footer' => ['text' => 'footer'],
            'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Yes', 'value' => null, 'parameters' => [['type' => 'static', 'value' => 'yes_payload']]],
                ['type' => 'URL', 'text' => 'Open', 'value' => 'https://x.test/{{1}}', 'parameters' => [['type' => 'static', 'value' => 'abc']]],
            ],
        ]);

        $this->assertSame([
            'name' => 'promo',
            'language' => ['code' => 'ar'],
            'components' => [
                ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => 'Sara']]],
                ['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => 'SAVE10'],
                    ['type' => 'text', 'text' => 'Sara Ali'],
                ]],
                ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 0, 'parameters' => [['type' => 'payload', 'payload' => 'yes_payload']]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => 1, 'parameters' => [['type' => 'text', 'text' => 'abc']]],
            ],
        ], $template);
    }

    public function test_image_header_with_uploaded_url(): void
    {
        $template = $this->build([
            'header' => [
                'format' => 'IMAGE',
                'parameters' => [['type' => 'IMAGE', 'selection' => 'upload', 'value' => 'https://s3.test/a.jpg']],
            ],
            'body' => ['text' => 'Hello', 'parameters' => []],
            'footer' => ['text' => null],
            'buttons' => [],
            'media' => 12,
        ]);

        $this->assertSame([
            'name' => 'promo',
            'language' => ['code' => 'ar'],
            'components' => [
                ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://s3.test/a.jpg']]]],
            ],
        ], $template);
    }

    public function test_video_header_with_default_url(): void
    {
        $template = $this->build([
            'header' => [
                'format' => 'VIDEO',
                'parameters' => [['type' => 'VIDEO', 'selection' => 'default', 'value' => 'https://s3.test/v.mp4']],
            ],
            'body' => ['parameters' => []],
            'buttons' => [],
        ]);

        $this->assertSame(
            [['type' => 'header', 'parameters' => [['type' => 'video', 'video' => ['link' => 'https://s3.test/v.mp4']]]]],
            $template['components']
        );
    }

    public function test_template_without_header(): void
    {
        $template = $this->build([
            'header' => ['format' => null, 'parameters' => []],
            'body' => [
                'text' => 'Hi {{1}}',
                'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => '']],
            ],
            'buttons' => [],
        ]);

        // قيمة فارغة تُملأ برقم الهاتف لا تُرسل فارغة.
        $this->assertSame(
            [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '+966500000001']]]],
            $template['components']
        );
    }

    public function test_copy_code_button_and_buttons_without_parameters(): void
    {
        $template = $this->build([
            'header' => ['format' => null, 'parameters' => []],
            'body' => ['parameters' => []],
            'buttons' => [
                ['type' => 'PHONE_NUMBER', 'text' => 'Call', 'value' => '+966500000000', 'parameters' => []],
                ['type' => 'COPY_CODE', 'text' => 'Copy', 'value' => null, 'parameters' => [['type' => 'static', 'value' => 'CODE1']]],
            ],
        ]);

        $this->assertSame(
            [['type' => 'button', 'sub_type' => 'copy_code', 'index' => 1, 'parameters' => [['type' => 'coupon_code', 'coupon_code' => 'CODE1']]]],
            $template['components']
        );
    }

    public function test_metadata_without_a_header_key(): void
    {
        $template = $this->build(['body' => ['parameters' => []], 'buttons' => []]);

        $this->assertSame([], $template['components']);
    }
}
