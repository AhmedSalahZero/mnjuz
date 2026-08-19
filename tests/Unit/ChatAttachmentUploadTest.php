<?php

namespace Tests\Unit;

use App\Http\Middleware\HandleInertiaRequests;
use ReflectionMethod;
use Tests\TestCase;

/**
 * اللصق والسحب والإفلات في الملحن.
 *
 * الجزء المصيري هنا ليس الواجهة بل حدّ الرفع: ملف أكبر ممّا يقبله PHP يُرفض
 * قبل بلوغ Laravel، فتصل حمولة بلا ملف ولا خطأ — يظنّ الموظّف أنه أرسل ولا
 * يصل العميل شيء. الحدّ يُقرأ من الخادم ويُمرَّر للواجهة لتردّه مبكّراً.
 *
 * المشروع بلا إطار اختبار JS، فمنطق الواجهة يُحرَس بنيوياً على الملف.
 */
class ChatAttachmentUploadTest extends TestCase
{
    private string $composer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->composer = file_get_contents(
            dirname(__DIR__, 2) . '/resources/js/Components/ChatComponents/ChatForm.vue'
        );
    }

    private function maxUploadBytes(): int
    {
        $method = new ReflectionMethod(HandleInertiaRequests::class, 'maxUploadBytes');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    // ------------------------------------------------- حدّ الرفع

    public function test_the_effective_limit_is_a_positive_number_of_bytes(): void
    {
        $this->assertGreaterThan(0, $this->maxUploadBytes());
    }

    /**
     * post_max_size يحكم الطلب كلّه وupload_max_filesize يحكم الملف، فالفعّال
     * هو الأصغر. أخذ الأكبر كان سيسمح بملف يرفضه الخادم.
     */
    public function test_the_effective_limit_is_the_smaller_of_the_two_directives(): void
    {
        $upload = $this->iniToBytes((string) ini_get('upload_max_filesize'));
        $post = $this->iniToBytes((string) ini_get('post_max_size'));

        $this->assertSame(min($upload, $post), $this->maxUploadBytes());
    }

    private function iniToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public function test_the_limit_is_shared_with_the_frontend(): void
    {
        $source = file_get_contents(base_path('app/Http/Middleware/HandleInertiaRequests.php'));

        $this->assertStringContainsString(
            "'max_upload_bytes' => self::maxUploadBytes()",
            $source,
            'بلا تمريره تظلّ الواجهة تقبل ملفاً يرفضه الخادم صامتاً'
        );
    }

    public function test_the_composer_rejects_files_above_the_server_limit_first(): void
    {
        $this->assertStringContainsString('serverMaxUploadBytes', $this->composer);
        $this->assertMatchesRegularExpression(
            '/if \(file\.size > serverMaxUploadBytes\.value\)/',
            $this->composer,
            'حدّ الخادم يجب أن يُفحَص قبل حدود واتساب — هو السقف الحقيقي'
        );
    }

    // --------------------------------------- اللصق والإفلات

    /**
     * @dataProvider documentListeners
     */
    public function test_every_listener_is_removed_on_unmount(string $event): void
    {
        $added = substr_count($this->composer, "document.addEventListener('{$event}'");
        $removed = substr_count($this->composer, "document.removeEventListener('{$event}'");

        $this->assertSame(1, $added, "المستمع {$event} يُضاف مرّة واحدة");
        $this->assertSame(
            $added,
            $removed,
            "المستمع {$event} يُضاف ولا يُزال — تسريب يتراكم مع كل فتح محادثة"
        );
    }

    public static function documentListeners(): array
    {
        return [
            'اللصق' => ['paste'],
            'دخول السحب' => ['dragenter'],
            'أثناء السحب' => ['dragover'],
            'مغادرة السحب' => ['dragleave'],
            'الإفلات' => ['drop'],
        ];
    }

    /**
     * لصق النصّ يجب أن يمرّ كما هو. الاعتراض غير المشروط كان سيعطّل أبسط
     * استخدام للملحن: لصق رسالة منسوخة.
     */
    public function test_pasting_plain_text_is_not_intercepted(): void
    {
        $this->assertMatchesRegularExpression(
            '/const handlePaste = \(event\) => \{\s*\n\s*const files = Array\.from\(event\.clipboardData\?\.files \?\? \[\]\)\s*\n\s*if \(!files\.length\) return/',
            $this->composer,
            'التدخّل يقع فقط حين تحمل الحافظة ملفاً'
        );
    }

    /** طبقة السحب تظهر للملفات وحدها لا لسحب نصّ أو رابط داخل الصفحة. */
    public function test_the_overlay_only_reacts_to_file_drags(): void
    {
        $this->assertMatchesRegularExpression(
            "/const isFileDrag = \(event\) => Array\.from\(event\.dataTransfer\?\.types \?\? \[\]\)\.includes\('Files'\)/",
            $this->composer
        );
        $this->assertSame(
            4,
            substr_count($this->composer, 'isFileDrag(event)'),
            'كل معالجات السحب الأربعة تفحص أن المسحوب ملفات'
        );
    }

    /**
     * dragenter وdragleave يتعاقبان على العناصر المتداخلة، فبلا عدّاد عمق
     * تختفي الطبقة كلّما مرّ المؤشّر فوق عنصر داخلي.
     */
    public function test_nested_elements_do_not_flicker_the_overlay(): void
    {
        $this->assertStringContainsString('dragDepth += 1', $this->composer);
        $this->assertStringContainsString('dragDepth = Math.max(0, dragDepth - 1)', $this->composer);
        $this->assertMatchesRegularExpression('/if \(dragDepth === 0\) isDraggingFile\.value = false/', $this->composer);
    }

    // ------------------------------------------- المعاينة والإرسال

    /**
     * اللصق والإفلات قد يقعان سهواً — لقطة شاشة في الحافظة تُلصق بضغطة —
     * فلا يُرسَل شيء قبل تأكيد صريح.
     */
    public function test_pasted_and_dropped_files_wait_for_confirmation(): void
    {
        $this->assertMatchesRegularExpression(
            '/const handlePaste = .*?queueAttachments\(files\)/s',
            $this->composer,
            'اللصق يضع في الطابور لا يُرسل'
        );
        $this->assertMatchesRegularExpression(
            '/const handleDrop = .*?queueAttachments\(event\.dataTransfer\?\.files\)/s',
            $this->composer,
            'الإفلات يضع في الطابور لا يُرسل'
        );
        $this->assertStringNotContainsString('queueAttachments(files)\n\tsendMessage()', $this->composer);
    }

    /** لصق ملف ثانٍ والمعاينة مفتوحة يعني «وهذا أيضاً» لا استبدال الأول. */
    public function test_a_second_paste_adds_instead_of_replacing(): void
    {
        $this->assertMatchesRegularExpression(
            '/pendingAttachments\.value = \[\.\.\.pendingAttachments\.value, \.\.\.accepted\]/',
            $this->composer
        );
    }

    /**
     * الإرسال تتابعاً: الخادم يبني رسالة لكل ملف، وإطلاقها معاً يقلب ترتيبها
     * في المحادثة حسب أيّها انتهى أولاً.
     */
    public function test_multiple_files_are_sent_one_after_another(): void
    {
        $this->assertMatchesRegularExpression(
            '/for \(const \[index, item\] of queue\.entries\(\)\) \{.*?await sendMessage\(\)/s',
            $this->composer,
            'الإرسال داخل حلقة بـawait لا Promise.all'
        );
    }

    /** روابط المعاينة تُحرَّر: بلا ذلك تتراكم في الذاكرة مع كل لصق. */
    public function test_preview_object_urls_are_revoked(): void
    {
        $this->assertStringContainsString('URL.revokeObjectURL', $this->composer);
        $this->assertMatchesRegularExpression(
            '/onBeforeUnmount\(\(\) => \{.*?releasePreviews\(\)/s',
            $this->composer,
            'التحرير عند التفكيك أيضاً لا عند الإغلاق فقط'
        );
    }

    /** النافذة المغلقة لا يجوز أن تقبل الإرسال، والمرسِل لا يُستدعى مرّتين. */
    public function test_sending_is_guarded_against_empty_and_double_submission(): void
    {
        $this->assertMatchesRegularExpression(
            '/if \(!pendingAttachments\.value\.length \|\| sendingAttachments\.value\) return/',
            $this->composer
        );
        $this->assertMatchesRegularExpression(
            '/if \(!isInboundChatWithin24Hours\.value\) return/',
            $this->composer,
            'نافذة الـ24 ساعة تُفحص قبل الإرسال'
        );
    }

    public function test_attachments_are_blocked_when_the_messaging_window_is_closed(): void
    {
        $this->assertMatchesRegularExpression(
            '/const queueAttachments = \(files\) => \{\s*\n\s*if \(!isInboundChatWithin24Hours\.value \|\| !files\?\.length\) return/',
            $this->composer,
            'لا طابور أصلاً خارج نافذة الـ24 ساعة'
        );
    }

    // ------------------------------------------------ الترجمة

    /**
     * @dataProvider newStrings
     */
    public function test_every_new_string_is_translated(string $key): void
    {
        $arabic = json_decode(file_get_contents(base_path('lang/ar.json')), true);

        $this->assertArrayHasKey($key, $arabic, "ترجمة عربية مفقودة: {$key}");
        $this->assertNotSame('', trim((string) $arabic[$key]));
    }

    public static function newStrings(): array
    {
        return array_map(fn ($s) => [$s], [
            'Drop files to send them',
            'Images, videos, audio and documents',
            'Send file',
            'Send :count files',
            'Add a caption...',
            'Remove',
            'Send',
            'Cancel',
            'Close',
            'Sending...',
            'This file type is not supported.',
            'File is larger than the :size limit.',
        ]);
    }
}
