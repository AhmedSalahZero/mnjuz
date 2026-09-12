<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * مسار الوسائط لا يُسلّم إلا ما داخل مجلّده.
 *
 * كان اسم الملف يُلصق بمسار التخزين كما يصل من العنوان، والمسار عامّ بلا
 * تسجيل دخول. فطلب `/media/..%2f..%2f.env` كان يخرج إلى جذر المشروع ويسلّم
 * ملف البيئة: كلمة مرور القاعدة وAPP_KEY وتوكنات الدفع. رُصدت محاولة فعلية
 * في الإنتاج بتاريخ 11 سبتمبر 2026، ونجت بالصدفة لأن `../` مجلّد لا ملف.
 */
class MediaPathTraversalTest extends TestCase
{
    use RefreshDatabase;

    private string $servable;
    private string $secret;
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(storage_path('app/public/test-media'));
        $this->servable = storage_path('app/public/test-media/photo.txt');
        File::put($this->servable, 'MEDIA-OK');

        // ملف حسّاس خارج المجلّد المخدوم، يمثّل .env وملفات العملاء المستوردة
        File::ensureDirectoryExists(storage_path('app/test-private'));
        $this->outside = storage_path('app/test-private/secret.txt');
        File::put($this->outside, 'TOP-SECRET');

        $this->secret = base_path('.env.traversal-probe');
        File::put($this->secret, 'APP_KEY=base64:PROBE');
    }

    protected function tearDown(): void
    {
        File::delete([$this->servable, $this->outside, $this->secret]);
        File::deleteDirectory(storage_path('app/public/test-media'));
        File::deleteDirectory(storage_path('app/test-private'));

        parent::tearDown();
    }

    // ------------------------------------------------- ما يجب أن يعمل

    public function test_a_media_file_is_still_served(): void
    {
        $response = $this->get('/media/public/test-media/photo.txt');

        $response->assertOk();

        // الردّ BinaryFileResponse يُبثّ من القرص، فمحتواه لا يُقرأ من الجسم
        $this->assertSame(
            realpath($this->servable),
            realpath($response->baseResponse->getFile()->getPathname())
        );
    }

    public function test_a_missing_file_is_a_clean_404(): void
    {
        $this->get('/media/public/test-media/nope.jpg')->assertNotFound();
    }

    // ------------------------------------------------- الطلب الذي انهار فعلاً

    /** `/media/..%2f` — المجلّد كان يعبر file_exists ثم ينهار عند التسليم. */
    public function test_the_request_that_crashed_production_is_a_404(): void
    {
        $this->get('/media/' . rawurlencode('../'))->assertNotFound();
        $this->get('/media/..%2f')->assertNotFound();
    }

    public function test_a_directory_is_never_served(): void
    {
        $this->get('/media/public')->assertNotFound();
        $this->get('/media/public/test-media')->assertNotFound();
    }

    // ------------------------------------------------- التسلّل

    /** @dataProvider traversalAttempts */
    public function test_traversal_attempts_are_blocked(string $path): void
    {
        $response = $this->get('/media/' . $path);

        $response->assertNotFound();
        $this->assertStringNotContainsString('APP_KEY', $response->getContent());
        $this->assertStringNotContainsString('TOP-SECRET', $response->getContent());
    }

    public static function traversalAttempts(): array
    {
        return [
            'صعود إلى جذر المشروع' => ['../.env.traversal-probe'],
            'صعود مرّتين' => ['../../.env.traversal-probe'],
            'صعود مُرمَّز' => ['..%2f.env.traversal-probe'],
            'صعود بعد مجلّد صحيح' => ['public/../../.env.traversal-probe'],
            'صعود متكرّر' => ['public/../../../../../../etc/passwd'],
            'مسار مطلق' => ['/etc/passwd'],
            'شرطة خلفية' => ['..\\.env.traversal-probe'],
            'نقاط متتالية' => ['....//....//.env.traversal-probe'],
        ];
    }

    /**
     * الملف خارج مجلّد public لا يُسلَّم ولو عُرف اسمه بالضبط.
     *
     * تحت storage/app ملفات العملاء المستوردة والملفات المؤقّتة — لا شأن
     * لمسار الوسائط بها.
     */
    public function test_a_file_outside_the_public_directory_is_not_served(): void
    {
        $response = $this->get('/media/test-private/secret.txt');

        $response->assertNotFound();
        $this->assertStringNotContainsString('TOP-SECRET', $response->getContent());
    }

    /** ولا فرق في الردّ بين مفقود ومحظور — لا يستدلّ الفاحص على شيء. */
    public function test_blocked_and_missing_look_identical(): void
    {
        $blocked = $this->get('/media/test-private/secret.txt');
        $missing = $this->get('/media/public/test-media/nope.jpg');

        $this->assertSame($missing->status(), $blocked->status());
        $this->assertSame($missing->getContent(), $blocked->getContent());
    }
}
