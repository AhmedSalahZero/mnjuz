<?php

namespace Tests\Feature;

use App\Helpers\ChatMediaUploadHelper;
use Tests\TestCase;

/**
 * سقف الطلب الواحد: أصغر ما بين حدّ PHP وحدّ الطريق إليه.
 *
 * عطلٌ وقع على الإنتاج: ثلاثة ملفات PDF تُرفع كلٌّ على حدة بلا مشكلة، وترفع
 * مجتمعةً فيتجمّد الشريط عند ٧٦٪ بلا رسالة. السبب أن Cloudflare يرفض ما
 * تجاوز ١٠٠ ميغابايت ويقطع الاتصال **قبل** أن تبلغ الحمولة الخادم — فلا 413
 * في سجلّ nginx بل 499، ولا خطأ يصل المتصفّح.
 *
 * والنسبة نفسها دليل: ١٠٠ ÷ ١٣٠ ≈ ٧٧٪ — عندها ينقطع.
 *
 * ورفع post_max_size لا يُصلح شيئاً: الحدّ خارج PHP ولا سبيل إلى قراءته من
 * داخله. فيُعلَن في الإعداد، ويُؤخذ الأصغر — لأن أيّهما تجاوزناه سقط الطلب.
 */
class UploadRequestCeilingTest extends TestCase
{
    private function withCeiling(?int $bytes): int
    {
        config(['chat.max_request_bytes' => $bytes]);

        return ChatMediaUploadHelper::maxRequestBytes();
    }

    // ------------------------------------------------ أخذ الأصغر

    /** حدّ الوكيل أصغر ⇒ هو السقف، مهما رُفع post_max_size. */
    public function test_the_proxy_limit_wins_when_it_is_smaller(): void
    {
        $php = ChatMediaUploadHelper::phpMaxPostBytes();

        $this->assertSame(1024, $this->withCeiling(1024), 'حدّ الوكيل أُهمل — سيمرّ طلب يقطعه الوكيل.');
        $this->assertLessThanOrEqual($php, $this->withCeiling(1024));
    }

    /** وحدّ PHP أصغر ⇒ هو السقف. */
    public function test_the_php_limit_wins_when_it_is_smaller(): void
    {
        $php = ChatMediaUploadHelper::phpMaxPostBytes();

        $this->assertSame($php, $this->withCeiling($php * 10), 'حدّ PHP تُجووز — سيرفض الخادم الحمولة.');
    }

    /** وبلا إعلان يُرجَع حدّ PHP وحده — السلوك السابق يبقى صالحاً. */
    public function test_an_unset_ceiling_falls_back_to_php(): void
    {
        $php = ChatMediaUploadHelper::phpMaxPostBytes();

        $this->assertSame($php, $this->withCeiling(null));
        $this->assertSame($php, $this->withCeiling(0));
    }

    // ------------------------------------------------- القيمة الافتراضية

    /**
     * الافتراضي دون مئة Cloudflare بهامش. مئة بالضبط تسقط: الحمولة تحمل
     * حدود multipart وترويسات فوق حجم الملفات.
     */
    public function test_the_shipped_default_stays_under_the_cloudflare_limit(): void
    {
        $default = (int) (require base_path('config/chat.php'))['max_request_bytes'];

        $this->assertLessThan(100 * 1024 * 1024, $default, 'الافتراضي عند حدّ Cloudflare أو فوقه.');
        $this->assertGreaterThanOrEqual(50 * 1024 * 1024, $default, 'الافتراضي منخفض بلا داعٍ.');
    }

    /** وقابل للرفع لمن رقّى خطته. */
    public function test_the_ceiling_is_configurable(): void
    {
        $this->assertSame('CHAT_MAX_REQUEST_BYTES', $this->envKey(), 'السقف غير قابل للضبط من البيئة.');
    }

    private function envKey(): string
    {
        $source = file_get_contents(base_path('config/chat.php'));

        return preg_match("/env\('(CHAT_MAX_REQUEST_BYTES)'/", $source, $m) ? $m[1] : '';
    }

    // ------------------------------------------------- ما يصل الواجهة

    /** الواجهة تقيس الدفعة بهذا السقف لا بسقف الملف. */
    public function test_the_shared_prop_uses_the_request_ceiling(): void
    {
        $middleware = file_get_contents(base_path('app/Http/Middleware/HandleInertiaRequests.php'));

        $this->assertMatchesRegularExpression(
            "/'max_post_bytes' => \\\\App\\\\Helpers\\\\ChatMediaUploadHelper::maxRequestBytes\(\)/",
            $middleware,
            'الواجهة تقرأ حدّ PHP وحده — لن ترى سقف الوكيل.'
        );
    }

    /** وسقف الملف يبقى منفصلاً — الحدّان يقيسان شيئين مختلفين. */
    public function test_the_per_file_limit_stays_separate(): void
    {
        $middleware = file_get_contents(base_path('app/Http/Middleware/HandleInertiaRequests.php'));

        $this->assertStringContainsString('phpMaxUploadBytes()', $middleware);
        $this->assertNotSame(
            ChatMediaUploadHelper::phpMaxUploadBytes(),
            ChatMediaUploadHelper::maxRequestBytes(),
            'الحدّان متساويان — أحدهما يقيس الملف والآخر الطلب'
        );
    }
}
