<?php

namespace Tests\Unit\Email;

use App\Services\Email\EmailDesign;
use App\Services\Messaging\CampaignChannels;
use Tests\TestCase;

/**
 * شكل تصميم قالب البريد: ما يُقبل، وما يُخزَّن (قائمة سماح)، والنسخة النصّية.
 */
class EmailDesignTest extends TestCase
{
    private function design(array $blocks, array $settings = []): array
    {
        return ['settings' => $settings, 'blocks' => $blocks];
    }

    public function test_normalize_keeps_only_known_types_and_properties_with_defaults(): void
    {
        $normalized = EmailDesign::normalize($this->design([
            ['id' => 'a1', 'type' => 'heading', 'props' => ['text' => 'مرحباً', 'onclick' => 'alert(1)', 'color' => '#ABCDEF']],
            ['id' => 'a2', 'type' => 'script', 'props' => ['text' => 'x']],
            ['type' => 'spacer', 'props' => ['height' => 500]],
            ['id' => 'a1', 'type' => 'divider'],
        ]), 'ar');

        $this->assertSame(1, $normalized['version']);
        $this->assertSame('rtl', $normalized['settings']['direction']);
        $this->assertCount(3, $normalized['blocks']);

        [$heading, $spacer, $divider] = $normalized['blocks'];
        $this->assertSame(['text', 'color', 'align', 'font_size'], array_keys($heading['props']));
        $this->assertSame('#abcdef', $heading['props']['color']);
        $this->assertSame(28, $heading['props']['font_size']);
        $this->assertSame(96, $spacer['props']['height']);
        $this->assertMatchesRegularExpression('/^b[a-z0-9]{10}$/', $spacer['id']);
        // معرّف مكرّر يُستبدل.
        $this->assertNotSame('a1', $divider['id']);
    }

    public function test_settings_fall_back_to_defaults_and_follow_the_locale(): void
    {
        $this->assertSame('ltr', EmailDesign::normalize($this->design([]), 'en')['settings']['direction']);

        $settings = EmailDesign::normalize($this->design([], [
            'page_bg' => 'red', 'content_bg' => '#FFFFFF', 'font' => 'comic', 'direction' => 'rtl',
        ]), 'en')['settings'];

        $this->assertSame('#f4f5f7', $settings['page_bg']);
        $this->assertSame('#ffffff', $settings['content_bg']);
        $this->assertSame('tahoma', $settings['font']);
        $this->assertSame('rtl', $settings['direction']);
    }

    public function test_validation_reports_each_problem_at_its_path(): void
    {
        $errors = EmailDesign::validate($this->design([
            ['type' => 'heading', 'props' => ['text' => 'عنوان', 'color' => 'red']],
            ['type' => 'button', 'props' => ['label' => 'اطلب', 'url' => 'javascript:alert(1)']],
            ['type' => 'unknown'],
            ['type' => 'image', 'props' => ['src' => 'https://cdn.example.com/{first_name}.png']],
            ['type' => 'text', 'props' => ['text' => 'x', 'font_size' => 99]],
        ], ['page_bg' => 'blue']));

        $this->assertArrayHasKey('design.blocks.0.props.color', $errors);
        $this->assertArrayHasKey('design.blocks.1.props.url', $errors);
        $this->assertArrayHasKey('design.blocks.2.type', $errors);
        $this->assertArrayHasKey('design.blocks.3.props.src', $errors);
        $this->assertArrayHasKey('design.blocks.4.props.font_size', $errors);
        $this->assertArrayHasKey('design.settings.page_bg', $errors);
    }

    public function test_links_may_carry_placeholders(): void
    {
        $errors = EmailDesign::validate($this->design([
            ['type' => 'button', 'props' => ['label' => 'احجز', 'url' => 'https://shop.example/book?n={url:first_name}']],
            ['type' => 'social', 'props' => ['links' => [['network' => 'instagram', 'url' => 'https://instagram.com/shop']]]],
        ]));

        $this->assertSame([], $errors);
    }

    public function test_social_links_must_use_known_networks(): void
    {
        $errors = EmailDesign::validate($this->design([
            ['type' => 'text', 'props' => ['text' => 'x']],
            ['type' => 'social', 'props' => ['links' => [['network' => 'myspace', 'url' => 'https://x.example']]]],
        ]));

        $this->assertArrayHasKey('design.blocks.1.props.links', $errors);
    }

    public function test_an_empty_or_oversized_email_is_refused(): void
    {
        $this->assertArrayHasKey('design.blocks', EmailDesign::validate($this->design([])));
        $this->assertArrayHasKey('design.blocks', EmailDesign::validate($this->design([
            ['type' => 'divider'], ['type' => 'spacer'],
        ])));

        $tooMany = array_fill(0, EmailDesign::MAX_BLOCKS + 1, ['type' => 'divider']);
        $this->assertArrayHasKey('design.blocks', EmailDesign::validate($this->design($tooMany)));

        $long = array_fill(0, 4, ['type' => 'text', 'props' => ['text' => str_repeat('ب', 2900)]]);
        $this->assertGreaterThan(CampaignChannels::EMAIL_TEXT_MAX, 4 * 2900);
        $this->assertArrayHasKey('design.blocks', EmailDesign::validate($this->design($long)));
    }

    public function test_an_image_alone_is_enough_content(): void
    {
        $this->assertSame([], EmailDesign::validate($this->design([
            ['type' => 'image', 'props' => ['src' => 'https://cdn.example.com/banner.png']],
        ])));
    }

    public function test_plain_text_lists_the_readable_parts_in_order(): void
    {
        $design = EmailDesign::normalize($this->design([
            ['type' => 'header', 'props' => ['text' => '{organization_name}']],
            ['type' => 'heading', 'props' => ['text' => 'كل عام وأنتم بخير']],
            ['type' => 'spacer'],
            ['type' => 'button', 'props' => ['label' => 'احجز الآن', 'url' => 'https://shop.example/book']],
            ['type' => 'social', 'props' => ['links' => [['network' => 'x', 'url' => 'https://x.com/shop']]]],
            ['type' => 'footer', 'props' => ['text' => 'الرياض']],
        ]));

        $this->assertSame(
            "{organization_name}\n\nكل عام وأنتم بخير\n\nاحجز الآن: https://shop.example/book\n\nX: https://x.com/shop\n\nالرياض",
            EmailDesign::toPlainText($design)
        );
    }

    public function test_an_old_plain_text_template_opens_as_one_text_element(): void
    {
        $design = EmailDesign::fromPlainText("أهلاً {first_name}\nخصم اليوم", 'ar');

        $this->assertCount(1, $design['blocks']);
        $this->assertSame('text', $design['blocks'][0]['type']);
        $this->assertSame("أهلاً {first_name}\nخصم اليوم", $design['blocks'][0]['props']['text']);
    }

    public function test_safe_urls(): void
    {
        $this->assertTrue(EmailDesign::isSafeUrl('https://shop.example/عروض?x=1'));
        $this->assertTrue(EmailDesign::isSafeUrl('http://shop.example'));
        $this->assertFalse(EmailDesign::isSafeUrl('javascript:alert(1)'));
        $this->assertFalse(EmailDesign::isSafeUrl('ftp://shop.example'));
        $this->assertFalse(EmailDesign::isSafeUrl('https://shop.example/a b'));
        $this->assertFalse(EmailDesign::isSafeUrl('https://shop.example/"onmouseover="x'));
        $this->assertFalse(EmailDesign::isSafeUrl('//shop.example'));
    }
}
