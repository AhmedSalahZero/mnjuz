<?php

namespace Tests\Feature\ConversationCycle;

use App\Services\Messaging\Channel;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * الـ flow من الباب الحقيقي على كل قناة: الكلمة تبدأه، والأزرار تصل بشكل
 * المنصّة (أزرار واتساب، inline keyboard في تيليجرام، أزرار الـ widget)،
 * والضغطة تعود عبر الـ webhook أو الـ widget فتكمل الفرع الذي اختاره العميل.
 */
class FlowCycleTest extends ConversationCycleTestCase
{
    private function menuFlow(array $channels): void
    {
        $this->makeFlow([
            $this->startNode('keywords', 'القائمة'),
            $this->buttonsNode('2', 'كيف نساعدك؟', ['المبيعات', 'الدعم الفني']),
            $this->textNode('3', 'فريق المبيعات معك الآن'),
            $this->textNode('4', 'فريق الدعم معك الآن'),
        ], [['1', '2'], ['2', '3', 'a'], ['2', '4', 'b']], ['channels' => $channels]);
    }

    #[DataProvider('channels')]
    public function test_the_buttons_reach_the_customer_and_the_press_follows_its_branch(string $channel): void
    {
        $this->menuFlow(self::CHANNELS);

        $this->customerWrites($channel, 'القائمة');

        $this->assertSame(['كيف نساعدك؟'], $this->deliveredTexts($channel));
        $this->assertSame(['المبيعات', 'الدعم الفني'], $this->offeredButtons($channel));

        $this->customerPresses($channel, 'الدعم الفني');

        $this->assertSame(['كيف نساعدك؟', 'فريق الدعم معك الآن'], $this->deliveredTexts($channel));
    }

    #[DataProvider('channels')]
    public function test_a_flow_of_another_channel_does_not_start(string $channel): void
    {
        $this->menuFlow(array_values(array_diff(self::CHANNELS, [$channel])));

        $this->customerWrites($channel, 'القائمة');

        $this->assertSame([], $this->deliveredTexts($channel));
    }

    /** @return array<int, string> عناوين الأزرار كما وصلت إلى المنصّة. */
    private function offeredButtons(string $channel): array
    {
        $sent = last($this->deliveredTo($channel));

        return match ($channel) {
            Channel::WHATSAPP => array_map(fn ($b) => $b['reply']['title'], $sent['interactive']['action']['buttons'] ?? []),
            Channel::TELEGRAM => array_map(fn ($row) => $row[0]['text'], (is_string($sent['reply_markup'] ?? null) ? json_decode($sent['reply_markup'], true) : $sent['reply_markup'] ?? [])['inline_keyboard'] ?? []),
            Channel::WEBCHAT => array_map(fn ($b) => $b['title'], $sent['interactive']['buttons'] ?? []),
        };
    }
}
