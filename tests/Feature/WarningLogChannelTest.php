<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * التحذيرات في ملفّها المستقلّ.
 *
 * `Log::warning` كانت تختلط بكل شيء في laravel.log: بين آلاف أسطر debug وبين
 * الأخطاء الحقيقية، فلا تُقرأ ولا يُبنى عليها تنبيه.
 *
 * والفصل ليس مجرّد قناة جديدة: إعداد `level` في Monolog يقبل المستوى **وما
 * فوقه**، فقناة عند warning تبتلع error و critical معها. القصر على WARNING
 * بالضبط يحتاج قائمة قبول صريحة — وهذا ما تحرسه الاختبارات هنا، مع الشرط
 * المكمّل: ألّا يبقى التحذير في laravel.log أيضاً، وإلّا صار الفصل تكراراً.
 */
class WarningLogChannelTest extends TestCase
{
    private string $mainLog;
    private string $warningLog;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = Str::random(8);
        $this->mainLog = storage_path("logs/test_main_{$suffix}.log");
        $this->warningLog = storage_path("logs/test_warning_{$suffix}.log");

        config([
            'logging.channels.single.path' => $this->mainLog,
            'logging.channels.warning.path' => $this->warningLog,
        ]);

        app('log')->forgetChannel('single');
        app('log')->forgetChannel('warning');
    }

    protected function tearDown(): void
    {
        foreach ([$this->mainLog, $this->warningLog] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function write(string $level, string $message): void
    {
        Log::stack(['single', 'warning'])->{$level}($message);
    }

    private function contents(string $file): string
    {
        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    private function main(): string
    {
        return $this->contents($this->mainLog);
    }

    private function warnings(): string
    {
        return $this->contents($this->warningLog);
    }

    // ------------------------------------------------- جوهر الطلب

    public function test_a_warning_lands_in_the_warning_file(): void
    {
        $this->write('warning', 'رسالة تحذير');

        $this->assertStringContainsString('رسالة تحذير', $this->warnings());
    }

    /** والشرط المكمّل: لا تبقى في الملف الرئيسي أيضاً. */
    public function test_a_warning_does_not_stay_in_the_main_file(): void
    {
        $this->write('warning', 'رسالة تحذير');

        $this->assertStringNotContainsString('رسالة تحذير', $this->main(), 'التحذير مكرَّر في الملفّين.');
    }

    // ------------------------------------- ما لا يجوز أن ينجرف معه

    /**
     * الفخّ الذي يقع فيه الحلّ الساذج: `level => warning` يقبل ما فوقه أيضاً،
     * فينتهي ملف التحذيرات مليئاً بالأخطاء والانهيارات.
     */
    public function test_errors_and_criticals_stay_out_of_the_warning_file(): void
    {
        $this->write('error', 'خطأ حقيقي');
        $this->write('critical', 'انهيار');
        $this->write('emergency', 'طوارئ');

        $warnings = $this->warnings();

        $this->assertStringNotContainsString('خطأ حقيقي', $warnings);
        $this->assertStringNotContainsString('انهيار', $warnings);
        $this->assertStringNotContainsString('طوارئ', $warnings);
    }

    /** والمستويات الأدنى كذلك. */
    public function test_lower_levels_stay_out_of_the_warning_file(): void
    {
        $this->write('debug', 'تصحيح');
        $this->write('info', 'معلومة');
        $this->write('notice', 'ملاحظة');

        $warnings = $this->warnings();

        $this->assertStringNotContainsString('تصحيح', $warnings);
        $this->assertStringNotContainsString('معلومة', $warnings);
        $this->assertStringNotContainsString('ملاحظة', $warnings);
    }

    // -------------------------------- الملف الرئيسي لم يفقد غيرها

    public function test_the_main_file_still_receives_everything_else(): void
    {
        foreach (['debug' => 'تصحيح', 'info' => 'معلومة', 'notice' => 'ملاحظة',
                  'error' => 'خطأ', 'critical' => 'انهيار'] as $level => $message) {
            $this->write($level, $message);
        }

        $main = $this->main();

        foreach (['تصحيح', 'معلومة', 'ملاحظة', 'خطأ', 'انهيار'] as $message) {
            $this->assertStringContainsString($message, $main, "المستوى «{$message}» ضاع من الملف الرئيسي.");
        }
    }

    /** والمستوى مكتوب في السطر كما كان — الشكل لم يتغيّر. */
    public function test_the_line_format_is_unchanged(): void
    {
        $this->write('warning', 'صيغة');

        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} [\d:]+\] \w+\.WARNING: صيغة/', $this->warnings());
    }

    // ------------------------------------------------------ الأسلاك

    /** القناة في المكدّس، وإلّا لم يصلها شيء من `Log::warning` المباشرة. */
    public function test_the_warning_channel_is_part_of_the_default_stack(): void
    {
        $this->assertContains('warning', config('logging.channels.stack.channels'));
    }

    public function test_the_warning_channel_writes_to_its_own_file(): void
    {
        $default = require base_path('config/logging.php');

        $this->assertSame(storage_path('logs/warning.log'), $default['channels']['warning']['path']);
        $this->assertNotSame(
            $default['channels']['single']['path'],
            $default['channels']['warning']['path'],
            'القناتان تكتبان في ملف واحد — لا فصل.'
        );
    }

    /** المرشِّحان معاً: أحدهما بلا الآخر يعني تكراراً أو ضياعاً. */
    public function test_both_filters_are_wired(): void
    {
        $default = require base_path('config/logging.php');

        $this->assertContains(\App\Logging\WarningsOnly::class, $default['channels']['warning']['tap']);
        $this->assertContains(\App\Logging\WithoutWarnings::class, $default['channels']['single']['tap']);
    }
}
