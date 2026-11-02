<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * عدّاد نافذة الـ24 ساعة بجانب «Mark as closed».
 *
 * الحساب نفسه دالّة نقيّة تُختبر بـ node (tests/js/messaging-window.mjs). وهنا
 * الحراسة على الوصل: أن المكوّن موضوع في الترويسة، وأن حقل الكتابة يقرّر من
 * الوحدة نفسها لا من نسخة مكرّرة — فلا يعرض العدّاد «مفتوحة» والحقل مقفول —
 * وأن نصوصه مترجمة في كل لغة.
 */
class MessagingWindowCountdownTest extends TestCase
{
    private const HELPER = 'resources/js/Composables/messagingWindow.js';
    private const COUNTDOWN = 'resources/js/Components/ChatComponents/MessagingWindowCountdown.vue';
    private const HEADER = 'resources/js/Components/ChatComponents/ChatHeader.vue';
    private const FORM = 'resources/js/Components/ChatComponents/ChatForm.vue';
    private const INDEX = 'resources/js/Pages/User/Chat/Index.vue';

    private const KEYS = [
        '{hours}h {minutes}m left',
        '24-hour window closed',
        '{hours} hours and {minutes} minutes left before the 24-hour reply window closes',
    ];

    private function source(string $path): string
    {
        $this->assertFileExists(base_path($path));

        return file_get_contents(base_path($path));
    }

    private function nodeAvailable(): bool
    {
        exec('node --version 2>/dev/null', $output, $status);

        return $status === 0;
    }

    public function test_the_window_math_holds(): void
    {
        if (!$this->nodeAvailable()) {
            $this->markTestSkipped('node غير متاح في هذه البيئة');
        }

        $script = base_path('tests/js/messaging-window.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    // ------------------------------------------------- الترويسة

    public function test_the_header_shows_the_countdown(): void
    {
        $header = $this->source(self::HEADER);

        $this->assertStringContainsString("import MessagingWindowCountdown from '@/Components/ChatComponents/MessagingWindowCountdown.vue'", $header);
        $this->assertStringContainsString('<MessagingWindowCountdown :contact="contact" />', $header);
    }

    /** بجانب «Mark as closed» لا بعدها، وليس داخل شرط التذاكر. */
    public function test_it_sits_beside_mark_as_closed_and_outside_the_ticketing_condition(): void
    {
        $header = $this->source(self::HEADER);

        $countdown = strpos($header, '<MessagingWindowCountdown');
        $markClosed = strpos($header, "changeTicketStatus('closed')");

        $this->assertNotFalse($countdown);
        $this->assertNotFalse($markClosed);
        $this->assertLessThan($markClosed, $countdown, 'العدّاد يسبق الزر في الصف نفسه.');

        // السطر نفسه لا يحمل v-if: النافذة تهمّ الموظف سواء فُعّلت التذاكر أم لا.
        preg_match('/^.*<MessagingWindowCountdown.*$/m', $header, $line);
        $this->assertStringNotContainsString('v-if', $line[0]);
        $this->assertStringNotContainsString('ticketingIsEnabled', $line[0]);
    }

    /**
     * الفراغ بين العدّاد والزرّ يعمل في الاتجاهين.
     *
     * space-x-4 هامش فيزيائي على اليسار: في صفحة RTL يقع الهامش على الجهة البعيدة
     * عن العدّاد فيلتصق نصّه بالزرّ. gap منطقيّ فيصحّ في العربية والإنجليزية.
     */
    public function test_the_spacing_around_it_is_direction_safe(): void
    {
        $header = $this->source(self::HEADER);

        $this->assertMatchesRegularExpression(
            '/<div class="flex items-center gap-x-4">\s*<MessagingWindowCountdown/',
            $header
        );
        $this->assertDoesNotMatchRegularExpression(
            '/space-x-4">\s*<MessagingWindowCountdown/',
            $header,
            'space-x-4 لا يعمل في RTL: يلتصق نصّ العدّاد بالزرّ.'
        );
    }

    // ------------------------------------------------- مصدر واحد للحقيقة

    public function test_the_compose_box_decides_from_the_same_module(): void
    {
        $form = $this->source(self::FORM);

        $this->assertStringContainsString("from '@/Composables/messagingWindow'", $form);
        $this->assertStringContainsString('isWindowOpen(props.contact)', $form);
        $this->assertStringNotContainsString(
            'const parseUtcDate',
            $form,
            'نسخة مكرّرة من الحساب — قد تختلف عن العدّاد فيعرض «مفتوحة» والحقل مقفول.'
        );
        $this->assertStringNotContainsString('24 * 60 * 60 * 1000', $form);
    }

    public function test_the_countdown_uses_the_shared_module(): void
    {
        $countdown = $this->source(self::COUNTDOWN);

        $this->assertStringContainsString("from '@/Composables/messagingWindow'", $countdown);
        $this->assertStringContainsString('windowRemaining(lastInboundOf(props.contact)', $countdown);
    }

    public function test_the_countdown_cleans_up_its_timer(): void
    {
        $countdown = $this->source(self::COUNTDOWN);

        $this->assertStringContainsString('setInterval', $countdown);
        $this->assertStringContainsString('clearInterval', $countdown);
        $this->assertStringContainsString('onBeforeUnmount', $countdown);
    }

    // ------------------------------------------------- وصول رسالة جديدة

    /**
     * رسالة واردة تُصفّر العدّاد حتى لو المحادثة المفتوحة ليست في القائمة المحمّلة.
     *
     * كان السطر `currentContact.last_chat = …` يرمي حين لا توجد في القائمة
     * (بحث أو فلتر)، وecho.js يبتلع الخطأ — فلا يصل التحديث إلى العدّاد.
     */
    public function test_an_inbound_message_resets_the_open_conversation_even_when_it_is_not_in_the_list(): void
    {
        $index = $this->source(self::INDEX);

        $this->assertDoesNotMatchRegularExpression(
            '/if \(isChatFormOpen\) \{\s*updateChatThread\(chat\)\s*currentContact\.last_chat = currentChat/',
            $index,
            'الوصول إلى currentContact بلا فحص يرمي حين تكون المحادثة خارج القائمة.'
        );
        $this->assertStringContainsString('if (currentContact) {', $index);

        // تحديث المحادثة المفتوحة يقع قبل أي `return` مبكّر وخارج فرع القائمة.
        $reset = strpos($index, 'contact.value.last_inbound_chat_created_at_iso = inboundIso');
        $firstReturn = strpos($index, 'return', strpos($index, 'if (isInboundChat) {'));

        $this->assertNotFalse($reset);
        $this->assertNotFalse($firstReturn);
        $this->assertLessThan($firstReturn, $reset, 'تصفير العدّاد يأتي بعد خروج مبكّر — قد لا يُنفَّذ.');
        $this->assertSame(
            1,
            substr_count($index, 'contact.value.last_inbound_chat_created_at_iso = inboundIso'),
            'التحديث مكرّر — أحد النسختين داخل فرع القائمة.'
        );
    }

    // ------------------------------------------------- الترجمة

    /** @return array<string, array{0: string}> */
    public static function languages(): array
    {
        return array_combine(
            ['en', 'default_en', 'ar', 'fr', 'es', 'tr', 'sw'],
            array_map(fn ($l) => [$l], ['en', 'default_en', 'ar', 'fr', 'es', 'tr', 'sw'])
        );
    }

    /** @dataProvider languages */
    public function test_every_language_has_the_countdown_texts(string $language): void
    {
        $file = base_path("lang/{$language}.json");
        $this->assertFileExists($file);

        $translations = json_decode(file_get_contents($file), true);
        $this->assertIsArray($translations, "lang/{$language}.json ليس JSON صالحاً");

        foreach (self::KEYS as $key) {
            $this->assertArrayHasKey($key, $translations, "{$language}: المفتاح غير مترجم: {$key}");
            $this->assertNotSame('', trim($translations[$key]));
        }
    }

    /** الأرقام تُمرَّر بأسماء {hours}/{minutes} — ترجمة تُسقط أحدها تعرض نصّاً ناقصاً. */
    public function test_every_translation_keeps_its_placeholders(): void
    {
        foreach (array_keys(self::languages()) as $language) {
            $translations = json_decode(file_get_contents(base_path("lang/{$language}.json")), true);

            foreach (self::KEYS as $key) {
                if (str_contains($key, '{hours}')) {
                    $this->assertStringContainsString('{hours}', $translations[$key], "{$language}: {$key}");
                    $this->assertStringContainsString('{minutes}', $translations[$key], "{$language}: {$key}");
                }
            }
        }
    }
}
