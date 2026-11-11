<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * اختبارات الواجهة (tests/js/*.mjs) جزء من php artisan test.
 *
 * هي سكربتات Node مستقلّة تخرج بغير الصفر عند الفشل: منطق الـ widget،
 * ورسمه في متصفّح حقيقي، وحجمه، وصندوق الوارد. كانت تُشغَّل يدوياً فقط،
 * فيمرّ تعديلٌ يكسرها والـ suite أخضر.
 *
 * يُتخطّى السكربت (لا يسقط) حين لا يوجد node، أو حين يطلب متصفّحاً غير
 * موجود (يخرج بالرمز 3)، أو حين يحتاج ملفات البناء ولم تُبنَ.
 */
class JavascriptSuiteTest extends TestCase
{
    private const SKIP_EXIT_CODE = 3;

    /** @return array<string, array{0: string}> */
    public static function scripts(): array
    {
        $scripts = glob(dirname(__DIR__) . '/js/*.mjs') ?: [];
        sort($scripts);

        return array_combine(
            array_map('basename', $scripts),
            array_map(fn (string $path) => [$path], $scripts)
        );
    }

    #[DataProvider('scripts')]
    public function test_the_script_passes(string $script): void
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            $this->markTestSkipped('node is not installed');
        }

        if (str_contains($script, 'widget-size') && !is_file(base_path('public/widget/v1/app.js'))) {
            $this->markTestSkipped('widget bundle not built (npm run build:widget)');
        }

        $process = new Process([$node, $script], base_path(), null, null, 120);
        $process->run();

        if ($process->getExitCode() === self::SKIP_EXIT_CODE) {
            $this->markTestSkipped(trim($process->getOutput()));
        }

        $this->assertSame(0, $process->getExitCode(), basename($script) . " failed:\n" . $process->getOutput() . $process->getErrorOutput());
    }
}
