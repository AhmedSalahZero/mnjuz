<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * سبب فشل الرسالة كما يراه صاحب الحساب.
 *
 * ما جرى: حملة أُرسلت لرقمين في الثانية نفسها، فقبِلت واتساب الاثنين ثم
 * سلّمت واحداً وأسقطت الآخر بالكود 131049 (حدّ الرسائل التسويقية لكل
 * مستلِم). ولم تجد صاحبة الحساب في الواجهة إلا كلمة «failed» — فبدا الخلل
 * في نظامنا وهو قرار من واتساب لا حيلة لنا فيه.
 *
 * السبب كان مكتوباً في chat_status_logs طوال الوقت؛ الناقص عرضُه.
 */
class WhatsappErrorExplanationTest extends TestCase
{
    private function nodeAvailable(): bool
    {
        exec('node --version 2>/dev/null', $output, $status);

        return $status === 0;
    }

    public function test_the_explanation_logic_covers_the_codes_we_actually_see(): void
    {
        if (!$this->nodeAvailable()) {
            $this->markTestSkipped('node غير متاح في هذه البيئة');
        }

        $script = base_path('tests/js/whatsapp-errors.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    /**
     * الأكواد الأكثر تكراراً في الإنتاج خلال ثلاثة أشهر — أيّها بلا شرح
     * يعني آلاف الرسائل تُعرض «failed» بلا سبب:
     *   131042: 107,751 · 131049: 52,498 · 131026: 17,247 · 131048: 10,848
     *   130472: 3,893 · 131053: 952 · 131050: 211 · 131031: 139 · 131047: 90
     */
    public function test_every_frequent_production_code_has_an_arabic_explanation(): void
    {
        $module = file_get_contents(base_path('resources/js/Composables/whatsappErrors.js'));
        $translations = json_decode(file_get_contents(base_path('lang/ar.json')), true);

        foreach ([131042, 131049, 131026, 131048, 130472, 131053, 131050, 131031, 131047] as $code) {
            $this->assertMatchesRegularExpression(
                '/\b' . $code . ':\s*\'([^\']+)\'/',
                $module,
                "الكود {$code} بلا شرح"
            );

            preg_match('/\b' . $code . ':\s*\'([^\']+)\'/', $module, $matches);
            $key = $matches[1];

            $this->assertArrayHasKey($key, $translations, "شرح الكود {$code} بلا ترجمة عربية");
            $this->assertNotSame($key, $translations[$key], "شرح الكود {$code} غير مترجَم فعلياً");
        }
    }

    /**
     * سجلّ الحملة كان يعرض قائمة حالات بلا سبب: كتلة الخطأ فيه كانت داخل
     * تعليق HTML معطَّل، فلا شيء يصل الموظّف مهما كان الفشل.
     */
    public function test_the_campaign_log_shows_the_reason(): void
    {
        $table = file_get_contents(base_path('resources/js/Components/Tables/CampaignLogTable.vue'));

        $this->assertStringContainsString('explainWhatsappError', $table, 'سجلّ الحملة لا يترجم الخطأ');
        $this->assertStringContainsString('failureReason(log.metadata)', $table, 'سبب فشل التسليم غير معروض');
        $this->assertStringContainsString('$t(\'WhatsApp error code\')', $table, 'الكود غير معروض للدعم');
    }

    public function test_the_chat_bubble_shows_the_reason_above_the_raw_details(): void
    {
        $bubble = file_get_contents(base_path('resources/js/Components/ChatComponents/ChatBubble.vue'));

        $this->assertStringContainsString('explainWhatsappError', $bubble);
        $this->assertStringContainsString('failureReason', $bubble);
        $this->assertStringContainsString(
            '$t(\'Technical details\')',
            $bubble,
            'التفاصيل الخام يجب أن تبقى للدعم لكن تحت عنوان يفصلها عن السبب'
        );
    }

    /** الشرح مفتاح ترجمة يمرّ على $t، لا نصّاً إنجليزياً يُطبع كما هو. */
    public function test_the_explanation_is_translated_before_display(): void
    {
        foreach ([
            'resources/js/Components/Tables/CampaignLogTable.vue',
            'resources/js/Components/ChatComponents/ChatBubble.vue',
        ] as $file) {
            $source = file_get_contents(base_path($file));

            $this->assertMatchesRegularExpression(
                '/translatable\s*\r?\n?\s*\?\s*\$t\(/s',
                $source,
                "{$file} يعرض الشرح بلا ترجمة"
            );
        }
    }
}
