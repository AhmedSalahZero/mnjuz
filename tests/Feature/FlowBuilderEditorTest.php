<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * محرّر المسارات في المتصفح: الفحوص في tests/js/flow-editor.mjs (وصلات القائمة
 * بعد حذف صفّ، حصّة الأزرار، معرّفات العقد، التعديلات غير المحفوظة، وترجمة كل
 * ملف مسّه الإصلاح). هنا تُشغَّل مع بقية الاختبارات.
 */
class FlowBuilderEditorTest extends TestCase
{
    public function test_the_editor_checks_pass(): void
    {
        exec('node --version 2>/dev/null', $output, $status);
        if ($status !== 0 || !is_dir(base_path('node_modules/@vue/compiler-sfc'))) {
            $this->markTestSkipped('node أو @vue/compiler-sfc غير متاح في هذه البيئة');
        }

        $script = base_path('tests/js/flow-editor.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $result, $status);

        $this->assertSame(0, $status, implode("\n", $result));
        $this->assertStringContainsString('OK', implode("\n", $result));
    }
}
