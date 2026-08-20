<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * دمج الرسائل المبثوثة في شريط المحادثة.
 *
 * الرسالة الواحدة تصل أكثر من مرّة: بثّ عند الإرسال يحمل معرّفنا المؤقّت، ثم
 * بثّ لكل تغيّر حالة (sent/delivered/read) يحمل wam_id مكان المعرّف المؤقّت.
 * وصولها بترتيب متشابك كان يُنتج فقاعات مكرّرة — ملفّان مرفوعان يظهران ثلاثة
 * حتى إعادة تحميل الصفحة، والقاعدة فيها رسالتان فقط.
 *
 * ظهرت العلّة مع رفع عدّة ملفات دفعةً لأن بثّيهما يتزامنان، لكنها كانت قائمة
 * في كل إرسال.
 *
 * المنطق دالّة نقيّة في JavaScript، فيُشغَّل اختبارها السلوكي عبر node على
 * الملف المشحون نفسه لا على نسخة منه.
 */
class ChatThreadMergeTest extends TestCase
{
    private function nodeAvailable(): bool
    {
        exec('node --version 2>/dev/null', $output, $status);

        return $status === 0;
    }

    /**
     * كل تراتيب وصول البثّ الممكنة لملفّين، ثم حالات الحوافّ.
     *
     * الاختبار يستورد الدالّة من مصدرها، فما يُفحَص هو الكود المشحون.
     */
    public function test_broadcasts_merge_without_duplicates_in_any_order(): void
    {
        if (!$this->nodeAvailable()) {
            $this->markTestSkipped('node غير متاح في هذه البيئة');
        }

        $script = base_path('tests/js/merge-chat-into-thread.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('بلا تكرار ولا فقدان', implode("\n", $output));
    }

    /**
     * الفصل نفسه شرط للاختبار: إعادة المنطق داخل المكوّن تُعيده خارج التغطية،
     * وهو المنطق الذي أنتج العلّة مرّة.
     */
    public function test_the_merge_logic_stays_in_its_own_testable_module(): void
    {
        $this->assertFileExists(base_path('resources/js/Composables/mergeChatIntoThread.js'));

        $page = file_get_contents(base_path('resources/js/Pages/User/Chat/Index.vue'));

        $this->assertStringContainsString(
            "import { mergeChatIntoThread } from '@/Composables/mergeChatIntoThread'",
            $page
        );
        $this->assertMatchesRegularExpression(
            '/const updateChatThread = \(chat\) => \{.*?mergeChatIntoThread\(chatThread\.value, chat\)/s',
            $page,
            'المكوّن يجب أن يستدعي الدالّة النقيّة لا أن يُكرّر منطقها'
        );
    }

    /** التمرير لأسفل عند الإضافة وحدها: تحديث رسالة قائمة لا يقفز بالشاشة. */
    public function test_the_view_only_scrolls_when_a_message_is_appended(): void
    {
        $page = file_get_contents(base_path('resources/js/Pages/User/Chat/Index.vue'));

        $this->assertMatchesRegularExpression(
            '/if \(appended\) \{\s*\n\s*setTimeout\(scrollToBottom, 100\)/',
            $page
        );
    }
}
