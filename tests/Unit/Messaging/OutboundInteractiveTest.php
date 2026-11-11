<?php

namespace Tests\Unit\Messaging;

use App\Services\Messaging\OutboundInteractive;
use App\Services\WhatsappService;
use Tests\TestCase;

/**
 * الترجمة الوحيدة من وسائط واتساب التفاعلية إلى ما تفهمه القنوات الأخرى.
 * كل ردّ آلي وكل flow واستبيان على تيليجرام والمحادثة المباشرة يمرّ بها.
 */
class OutboundInteractiveTest extends TestCase
{
    public function test_reply_buttons_keep_their_ids_and_titles(): void
    {
        $message = OutboundInteractive::fromWhatsappArgs(
            'interactive buttons',
            'كيف نساعدك؟',
            [['id' => 'a', 'title' => 'المبيعات'], ['id' => 'b', 'title' => 'الدعم'], ['id' => 'c', 'title' => '  ']],
            ['type' => 'text', 'text' => 'أهلاً'],
            'نسعد بخدمتك',
            null
        );

        $this->assertSame(OutboundInteractive::BUTTONS, $message->kind);
        $this->assertSame([['id' => 'a', 'title' => 'المبيعات'], ['id' => 'b', 'title' => 'الدعم']], $message->buttons, 'an empty title is dropped');
        $this->assertSame(['type' => 'text', 'text' => 'أهلاً'], $message->header);
        $this->assertSame('نسعد بخدمتك', $message->footer);
    }

    public function test_a_list_keeps_sections_rows_and_descriptions(): void
    {
        $message = OutboundInteractive::fromWhatsappArgs(
            'interactive list',
            'اختر خدمة',
            [['title' => 'خدمات', 'rows' => [['id' => 'r1', 'title' => 'طلب جديد', 'description' => 'خلال يوم'], ['id' => 'r2', 'title' => 'متابعة', 'description' => '']]]],
            null,
            '',
            'القائمة'
        );

        $this->assertSame(OutboundInteractive::LIST, $message->kind);
        $this->assertSame('القائمة', $message->button);
        $this->assertSame([['title' => 'خدمات', 'rows' => [
            ['id' => 'r1', 'title' => 'طلب جديد', 'description' => 'خلال يوم'],
            ['id' => 'r2', 'title' => 'متابعة'],
        ]]], $message->sections);
        $this->assertNull($message->footer);
    }

    public function test_a_link_button_needs_a_web_url(): void
    {
        $ok = OutboundInteractive::fromWhatsappArgs(WhatsappService::CTA_URL, 'قيّمنا', ['display_text' => 'تقييم', 'url' => 'https://example.com/rate/x'], null, null, null);
        $this->assertSame(OutboundInteractive::CTA_URL, $ok->kind);
        $this->assertSame('https://example.com/rate/x', $ok->url);
        $this->assertSame('تقييم', $ok->displayText);

        $this->assertNull(OutboundInteractive::fromWhatsappArgs(WhatsappService::CTA_URL, 'x', ['display_text' => 'x', 'url' => 'javascript:alert(1)'], null, null, null));
    }

    public function test_nothing_usable_means_no_interactive_message(): void
    {
        $this->assertNull(OutboundInteractive::fromWhatsappArgs('text', 'x', [], null, null, null));
        $this->assertNull(OutboundInteractive::fromWhatsappArgs('interactive buttons', 'x', [], null, null, null));
        $this->assertNull(OutboundInteractive::fromWhatsappArgs('interactive list', 'x', [['title' => 's', 'rows' => []]], null, null, null));
    }

    /** القنوات بلا أزرار تقرأ الخيارات نصّاً: كل خيار في سطر، والرابط ظاهر. */
    public function test_the_text_fallback_lists_every_option(): void
    {
        $buttons = OutboundInteractive::fromWhatsappArgs('interactive buttons', 'كيف نساعدك؟', [['id' => 'a', 'title' => 'المبيعات'], ['id' => 'b', 'title' => 'الدعم']], null, 'شكراً', null);
        $this->assertSame("كيف نساعدك؟\n\n• المبيعات\n• الدعم\n\nشكراً", $buttons->fallbackText());

        $link = OutboundInteractive::fromWhatsappArgs(WhatsappService::CTA_URL, 'قيّمنا', ['display_text' => 'تقييم', 'url' => 'https://example.com/r'], null, null, null);
        $this->assertSame("قيّمنا\n\nتقييم: https://example.com/r", $link->fallbackText());
    }

    public function test_the_stored_options_describe_what_was_offered(): void
    {
        $message = OutboundInteractive::fromWhatsappArgs('interactive buttons', 'x', [['id' => 'a', 'title' => 'نعم']], null, null, null);

        $this->assertSame('buttons', $message->toOptions()['kind']);
        $this->assertSame([['id' => 'a', 'title' => 'نعم']], $message->toOptions()['buttons']);
        $this->assertSame($message->toOptions(), $message->toMetadata()['interactive_options']);
    }
}
