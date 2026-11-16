<?php

namespace Tests\Unit;

use App\Services\WebChat\WebChatPhone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * جوال زائر المحادثة المباشرة. كان كل ما ليس رقماً لاتينياً يُمسح قبل
 * التحليل: الأرقام العربية تُرفض، والحروف تُمسح بصمت، والأرضي يُقبل.
 */
class WebChatPhoneTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string, 2: ?string}> [المكتوب، E.164، السبب] */
    public static function strictCases(): array
    {
        return [
            'local' => ['0501234567', '+966501234567', null],
            'without the zero' => ['501234567', '+966501234567', null],
            'arabic-indic digits' => ['٠٥٠١٢٣٤٥٦٧', '+966501234567', null],
            'persian digits' => ['۰۵۰۱۲۳۴۵۶۷', '+966501234567', null],
            'spaces and dashes' => ['+966 50-123 4567', '+966501234567', null],
            'double zero prefix' => ['00966501234567', '+966501234567', null],
            'bidi marks from a copied number' => ["\u{200F}0501234567\u{200E}", '+966501234567', null],
            'non-breaking space' => ["050 123\u{00A0}4567", '+966501234567', null],
            'brackets and dots' => ['(050) 123.4567', '+966501234567', null],
            'another country with a plus' => ['+201001234567', '+201001234567', null],
            'us number that may be mobile' => ['+12015550123', '+12015550123', null],
            'letters around the number' => ['abc0501234567', null, WebChatPhone::ERROR_LETTERS],
            'letter O instead of zero' => ['05O1234567', null, WebChatPhone::ERROR_LETTERS],
            'arabic words' => ['رقمي 0501234567', null, WebChatPhone::ERROR_LETTERS],
            'riyadh landline' => ['0112345678', null, WebChatPhone::ERROR_NOT_MOBILE],
            'too short' => ['12', null, WebChatPhone::ERROR_INVALID],
            'reserved test number' => ['+15550001234', null, WebChatPhone::ERROR_INVALID],
            'plus in the middle' => ['+966+501234567', null, WebChatPhone::ERROR_INVALID],
            'empty' => ['   ', null, WebChatPhone::ERROR_INVALID],
        ];
    }

    #[DataProvider('strictCases')]
    public function test_the_widget_mobile_question(string $typed, ?string $e164, ?string $reason): void
    {
        $this->assertSame([$e164, $reason], WebChatPhone::strict($typed, 'SA'));
    }

    /** منشئ المسارات يستخرج الرقم من جملة العميل كما كان، أرضياً كان أو جوالاً. */
    public function test_free_text_from_a_flow_stays_lenient(): void
    {
        $this->assertSame('+966501234567', WebChatPhone::lenient('رقمي 0501234567', 'SA'));
        $this->assertSame('+966501234567', WebChatPhone::lenient('رقمي ٠٥٠١٢٣٤٥٦٧', 'SA'));
        $this->assertSame('+966112345678', WebChatPhone::lenient('0112345678', 'SA'));
        $this->assertNull(WebChatPhone::lenient('12', 'SA'));
    }

    public function test_each_reason_has_its_own_message(): void
    {
        $messages = array_map([WebChatPhone::class, 'message'], [
            WebChatPhone::ERROR_LETTERS, WebChatPhone::ERROR_NOT_MOBILE, WebChatPhone::ERROR_INVALID,
        ]);

        $this->assertCount(3, array_unique($messages));
        $this->assertSame('Enter a valid mobile number.', WebChatPhone::message(null));
    }
}
