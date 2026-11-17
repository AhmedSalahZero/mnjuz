<?php

namespace Tests\Unit\Email;

use App\Services\Email\EmailDesignRenderer;
use Tests\TestCase;

/**
 * HTML البريد المصمَّم: كل نصّ مهرَّب بعد الاستبدال، والروابط تُفحص بعد
 * الاستبدال، والتذييل حاضر دائماً.
 */
class EmailDesignRendererTest extends TestCase
{
    private function render(array $blocks, ?\Closure $resolve = null, array $settings = []): string
    {
        return app(EmailDesignRenderer::class)->render(
            ['settings' => $settings + ['direction' => 'rtl'], 'blocks' => $blocks],
            $resolve ?? fn (string $text) => $text,
            ['locale' => 'ar', 'subject' => 'عرض', 'fromName' => 'متجر المثال', 'unsubscribeUrl' => 'https://app.example/unsubscribe/abc']
        );
    }

    public function test_text_of_every_element_is_escaped(): void
    {
        $evil = '<script>alert(1)</script>';

        $html = $this->render([
            ['type' => 'header', 'props' => ['text' => $evil]],
            ['type' => 'heading', 'props' => ['text' => $evil]],
            ['type' => 'text', 'props' => ['text' => $evil . "\nسطر ثانٍ"]],
            ['type' => 'button', 'props' => ['label' => $evil, 'url' => 'https://shop.example']],
            ['type' => 'image', 'props' => ['src' => 'https://cdn.example.com/a.png', 'alt' => '"><img src=x onerror=1>']],
            ['type' => 'footer', 'props' => ['text' => $evil]],
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('onerror=1>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;<br>' . "\n" . 'سطر ثانٍ', $html);
    }

    public function test_placeholders_are_resolved_before_escaping(): void
    {
        $values = ['first_name' => '<b>نورة</b>', 'organization_name' => 'متجر المثال'];
        $resolve = fn (string $text) => preg_replace_callback(
            '/\{(url:)?(\w+)\}/u',
            fn ($m) => $m[1] ? rawurlencode($values[$m[2]]) : $values[$m[2]],
            $text
        );

        $html = $this->render([
            ['type' => 'header', 'props' => ['text' => '{organization_name}']],
            ['type' => 'text', 'props' => ['text' => 'أهلاً {first_name}']],
            ['type' => 'button', 'props' => ['label' => 'احجز', 'url' => 'https://shop.example/book?n={url:first_name}']],
        ], $resolve);

        $this->assertStringContainsString('متجر المثال', $html);
        $this->assertStringContainsString('أهلاً &lt;b&gt;نورة&lt;/b&gt;', $html);
        $this->assertStringContainsString('href="https://shop.example/book?n=' . e(rawurlencode('<b>نورة</b>')) . '"', $html);
    }

    public function test_a_link_that_is_unsafe_after_resolving_is_dropped(): void
    {
        $html = $this->render(
            [['type' => 'button', 'props' => ['label' => 'افتح', 'url' => 'https://{first_name}']]],
            fn (string $text) => str_replace('{first_name}', 'evil.example/"x', $text)
        );

        $this->assertStringContainsString('افتح', $html);
        $this->assertStringNotContainsString('evil.example', $html);
    }

    public function test_the_unsubscribe_footer_is_always_there(): void
    {
        $html = $this->render([['type' => 'spacer']]);

        $this->assertStringContainsString('href="https://app.example/unsubscribe/abc"', $html);
        $this->assertStringContainsString(e(__('You received this email from :name.', ['name' => 'متجر المثال'])), $html);
    }

    public function test_direction_and_alignment_follow_the_settings(): void
    {
        $rtl = $this->render([['type' => 'text', 'props' => ['text' => 'نص', 'align' => 'start']]]);
        $this->assertStringContainsString('dir="rtl"', $rtl);
        $this->assertStringContainsString('text-align:right', $rtl);

        $ltr = $this->render([['type' => 'text', 'props' => ['text' => 'Text', 'align' => 'start']]], null, ['direction' => 'ltr']);
        $this->assertStringContainsString('dir="ltr"', $ltr);
        $this->assertStringContainsString('text-align:left', $ltr);
    }

    public function test_empty_elements_are_skipped_and_half_empty_image_text_degrades(): void
    {
        $html = $this->render([
            ['type' => 'heading', 'props' => ['text' => '']],
            ['type' => 'image', 'props' => ['src' => '']],
            ['type' => 'image_text', 'props' => ['src' => '', 'text' => 'نص وحده']],
        ]);

        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('mnjz-stack" width="40%"', $html);
        $this->assertStringContainsString('نص وحده', $html);
    }

    public function test_image_text_puts_the_image_on_the_chosen_side(): void
    {
        $start = $this->render([['type' => 'image_text', 'props' => ['src' => 'https://cdn.example.com/a.png', 'text' => 'وصف', 'image_position' => 'start']]]);
        $end = $this->render([['type' => 'image_text', 'props' => ['src' => 'https://cdn.example.com/a.png', 'text' => 'وصف', 'image_position' => 'end']]]);

        $this->assertLessThan(strpos($start, 'وصف'), strpos($start, 'cdn.example.com/a.png'));
        $this->assertGreaterThan(strpos($end, 'وصف'), strpos($end, 'cdn.example.com/a.png'));
    }
}
