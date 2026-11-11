<?php

namespace Tests\Unit\Campaigns;

use App\Services\CampaignRetryService;
use App\Traits\TemplateTrait;
use PHPUnit\Framework\TestCase;

/**
 * ما يُبنى من حملة محفوظة لكل عميل قبل أن يذهب إلى Meta: الترويسة بأنواعها،
 * والمتغيّرات الثابتة والمأخوذة من بيانات العميل، والأزرار بمواضعها الحقيقية
 * في القالب. بلا قاعدة بيانات ولا شبكة.
 */
class TemplatePayloadTest extends TestCase
{
    private object $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new class {
            use TemplateTrait;
        };
    }

    private function build(array $metadata, array $contact = []): array
    {
        $contact = (object) array_merge(['first_name' => 'سارة', 'last_name' => 'علي', 'email' => 's@example.com', 'phone' => '+966500000011'], $contact);

        return $this->builder->buildTemplate('offer', 'ar', json_decode(json_encode($metadata)), $contact);
    }

    private function component(array $template, string $type): ?array
    {
        foreach ($template['components'] as $component) {
            if ($component['type'] === $type) {
                return $component;
            }
        }

        return null;
    }

    public function test_name_and_language(): void
    {
        $template = $this->build(['header' => ['parameters' => []], 'body' => ['parameters' => []], 'buttons' => []]);

        $this->assertSame(['name' => 'offer', 'language' => ['code' => 'ar'], 'components' => []], $template);
    }

    public function test_each_media_header_is_sent_as_a_link(): void
    {
        foreach (['IMAGE' => 'image', 'VIDEO' => 'video', 'DOCUMENT' => 'document'] as $type => $key) {
            $template = $this->build(['header' => ['format' => $type, 'parameters' => [['type' => $type, 'value' => "https://cdn.example.com/f.{$key}"]]]]);

            $this->assertSame(
                ['type' => 'header', 'parameters' => [['type' => $key, $key => ['link' => "https://cdn.example.com/f.{$key}"]]]],
                $this->component($template, 'header'),
                $type
            );
        }
    }

    public function test_a_text_header_takes_its_value(): void
    {
        $template = $this->build(['header' => ['format' => 'TEXT', 'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'عرض اليوم']]]]);

        $this->assertSame([['type' => 'text', 'text' => 'عرض اليوم']], $this->component($template, 'header')['parameters']);
    }

    /** المتغيّرات من بيانات كل عميل: الاسم الأول، والأخير، والكامل، والبريد، والرقم. */
    public function test_dynamic_values_come_from_each_customer(): void
    {
        $template = $this->build(['body' => ['parameters' => array_map(
            fn ($field) => ['type' => 'text', 'selection' => 'dynamic', 'value' => $field],
            ['first name', 'last name', 'name', 'email', 'phone']
        )]]);

        $this->assertSame(
            ['سارة', 'علي', 'سارة علي', 's@example.com', '+966500000011'],
            array_column($this->component($template, 'body')['parameters'], 'text')
        );
    }

    /**
     * قيمةٌ فارغة لا تُرسل فارغة (Meta ترفض المعامل الفارغ فتفشل الرسالة):
     * تحلّ محلّها رقم العميل، فإن لم يوجد «-». سلوكٌ قائم يُثبَّت هنا.
     */
    public function test_an_empty_value_falls_back_to_the_phone_then_a_dash(): void
    {
        $metadata = ['body' => ['parameters' => [
            ['type' => 'text', 'selection' => 'dynamic', 'value' => 'email'],
            ['type' => 'text', 'selection' => 'static', 'value' => '  '],
        ]]];

        $this->assertSame(['+966500000011', '+966500000011'], array_column($this->component($this->build($metadata, ['email' => null]), 'body')['parameters'], 'text'));
        $this->assertSame(['-', '-'], array_column($this->component($this->build($metadata, ['email' => null, 'phone' => null]), 'body')['parameters'], 'text'));
    }

    /** الأزرار بمواضعها في القالب: زرٌّ بلا متغيّر لا يُرسل لكنه يحفظ موضعه. */
    public function test_buttons_keep_their_template_position(): void
    {
        $template = $this->build(['buttons' => [
            ['type' => 'QUICK_REPLY', 'text' => 'نعم', 'parameters' => []],
            ['type' => 'URL', 'text' => 'المتجر', 'parameters' => [['type' => 'static', 'value' => 'eid']]],
            ['type' => 'COPY_CODE', 'text' => 'انسخ', 'parameters' => [['type' => 'static', 'value' => 'SAVE20']]],
            ['type' => 'QUICK_REPLY', 'text' => 'إيقاف', 'parameters' => [['type' => 'static', 'value' => 'stop']]],
        ]]);

        $this->assertSame([
            ['type' => 'button', 'sub_type' => 'url', 'index' => 1, 'parameters' => [['type' => 'text', 'text' => 'eid']]],
            ['type' => 'button', 'sub_type' => 'copy_code', 'index' => 2, 'parameters' => [['type' => 'coupon_code', 'coupon_code' => 'SAVE20']]],
            ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 3, 'parameters' => [['type' => 'payload', 'payload' => 'stop']]],
        ], $template['components']);
    }

    /** حملة قديمة محفوظة بلا ترويسة ولا أزرار تُرسل بلا خطأ. */
    public function test_an_old_campaign_without_header_or_buttons_still_builds(): void
    {
        $template = $this->build(['body' => ['parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'أهلاً']]]]);

        $this->assertSame([['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'أهلاً']]]], $template['components']);
    }

    /** أخطاء Meta النهائية لا تُعاد (رقمٌ ليس على واتساب، مستلمٌ رافض...) وما سواها يُعاد. */
    public function test_which_failures_are_worth_retrying(): void
    {
        $service = new CampaignRetryService();

        foreach ([131026, 131050, 131047, 130472] as $code) {
            $this->assertFalse($service->isRetryableFailure([['code' => $code]]), (string) $code);
        }
        foreach ([131049, 131042, 131048, 131053, 131000] as $code) {
            $this->assertTrue($service->isRetryableFailure([['code' => $code]]), (string) $code);
        }
        $this->assertTrue($service->isRetryableFailure([]), 'no code: retry');
    }
}
