<?php

namespace Tests\Feature;

use App\Services\Chat\ChunkedUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * تجميع المرفقات المرفوعة على قطع.
 *
 * العطل: Cloudflare يقطع أي طلب تجاوز ١٢٥ ثانية — لا أي حجم بعينه. أُعيد
 * إنتاجه بالضبط: نفس الملف عبر Cloudflare يُقطع عند 125.005 ثانية برمز 524،
 * ومباشرةً إلى الخادم يكتمل في 184 ثانية. وبسرعة ٠٫٣ ميغابايت/ثانية يفشل أي
 * ملف فوق ~٤٠ ميغابايت مهما أُرسل وحده.
 *
 * الحلّ ألّا يطول أي طلب. وما يُختبَر هنا هو الطرف الذي يجمع: أن الملف يعود
 * كما كان بايتاً ببايت، وأن المسارات لا تُبنى من اسمٍ يأتي من المتصفّح.
 */
class ChunkedUploadTest extends TestCase
{
    use RefreshDatabase;

    private const ORG = 7;
    private const USER = 3;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function directory(string $uploadId = 'abc-123'): string
    {
        return ChunkedUploadService::directoryFor(self::ORG, self::USER, $uploadId);
    }

    private function putChunks(string $directory, array $parts): void
    {
        foreach ($parts as $index => $content) {
            ChunkedUploadService::storeChunk(
                $directory,
                $index,
                UploadedFile::fake()->createWithContent('chunk', $content)
            );
        }
    }

    // ------------------------------------------------------ الدمج

    /** الملف يعود كما كان — الترتيب والمحتوى معاً. */
    public function test_the_chunks_reassemble_into_the_original_bytes(): void
    {
        $directory = $this->directory();
        $this->putChunks($directory, ['AAA', 'BBB', 'CCC']);

        $path = ChunkedUploadService::assemble($directory, 3, 'pdf');

        $this->assertNotNull($path);
        $this->assertSame('AAABBBCCC', Storage::disk('local')->get($path));
    }

    /** والترتيب بالفهرس لا بترتيب الوصول: القطع تصل كما تشاء الشبكة. */
    public function test_the_order_follows_the_index_not_arrival(): void
    {
        $directory = $this->directory();

        foreach ([2 => 'CCC', 0 => 'AAA', 1 => 'BBB'] as $index => $content) {
            ChunkedUploadService::storeChunk(
                $directory,
                $index,
                UploadedFile::fake()->createWithContent('chunk', $content)
            );
        }

        $this->assertSame('AAABBBCCC', Storage::disk('local')->get(
            ChunkedUploadService::assemble($directory, 3, 'pdf')
        ));
    }

    /**
     * وأكثر من عشر قطع تبقى مرتّبة.
     *
     * الترتيب مضمون لأن الدمج يمرّ على الفهارس عدداً لا على قائمة المجلّد —
     * وهذا ما يحرسه الاختبار: أي دمجٍ يعتمد على ترتيب القائمة يقلب ١٠ قبل ٢.
     */
    public function test_more_than_ten_chunks_stay_in_order(): void
    {
        $directory = $this->directory();
        $expected = '';

        for ($i = 0; $i < 12; $i++) {
            $content = chr(ord('a') + $i);
            $expected .= $content;
            ChunkedUploadService::storeChunk($directory, $i, UploadedFile::fake()->createWithContent('c', $content));
        }

        $this->assertSame($expected, Storage::disk('local')->get(
            ChunkedUploadService::assemble($directory, 12, 'pdf')
        ));
    }

    /** الامتداد يُحفظ: الخادم يستنتج نوع المحتوى منه عند الإرسال. */
    public function test_the_extension_is_preserved(): void
    {
        $directory = $this->directory();
        $this->putChunks($directory, ['x']);

        $this->assertStringEndsWith('.pdf', ChunkedUploadService::assemble($directory, 1, 'pdf'));
    }

    // -------------------------------------------- الاكتمال

    /** قطعة ناقصة ⇒ لا دمج: ملفٌ ناقص يصل العميل تالفاً بلا أن يشكّ أحد. */
    public function test_a_missing_chunk_prevents_assembly(): void
    {
        $directory = $this->directory();
        ChunkedUploadService::storeChunk($directory, 0, UploadedFile::fake()->createWithContent('c', 'A'));
        ChunkedUploadService::storeChunk($directory, 2, UploadedFile::fake()->createWithContent('c', 'C'));

        $this->assertFalse(ChunkedUploadService::hasAllChunks($directory, 3));
        $this->assertNull(ChunkedUploadService::assemble($directory, 3, 'pdf'));
    }

    public function test_it_reports_how_many_chunks_arrived(): void
    {
        $directory = $this->directory();
        $this->putChunks($directory, ['A', 'B']);

        $this->assertSame(2, ChunkedUploadService::receivedCount($directory, 5));
        $this->assertSame(2, ChunkedUploadService::receivedCount($directory, 2));
    }

    /** والقطع تُحذف بعد الدمج — بقاؤها يُضاعف المساحة لكل ملف. */
    public function test_the_parts_are_removed_after_assembly(): void
    {
        $directory = $this->directory();
        $this->putChunks($directory, ['A', 'B']);

        ChunkedUploadService::assemble($directory, 2, 'pdf');

        $this->assertFalse(Storage::disk('local')->exists($directory));
    }

    // -------------------------------------------------- الأمان

    /**
     * المسار يُبنى من معرّف الرفع، والمعرّف يأتي من المتصفّح — فلا يُقبل منه
     * ما يخرج بالكتابة عن المجلّد المقصود.
     */
    public function test_a_traversing_upload_id_cannot_escape_the_directory(): void
    {
        foreach (['../../etc', '..%2F..%2Fx', 'a/../../b', './../x'] as $hostile) {
            $directory = ChunkedUploadService::directoryFor(self::ORG, self::USER, $hostile);

            $this->assertStringStartsWith('temp/chunked-uploads/' . self::ORG . '/' . self::USER . '/', $directory);
            $this->assertStringNotContainsString('..', $directory, "المعرّف «{$hostile}» خرج عن المجلّد.");
            $this->assertStringNotContainsString('/', ChunkedUploadService::sanitizeId($hostile));
        }
    }

    /** ورفع كل مستخدم في مجلّده: معرّفٌ مُخمَّن لا يبلغ رفع غيره. */
    public function test_uploads_are_scoped_per_organization_and_user(): void
    {
        $this->assertNotSame(
            ChunkedUploadService::directoryFor(1, 1, 'same'),
            ChunkedUploadService::directoryFor(2, 1, 'same')
        );

        $this->assertNotSame(
            ChunkedUploadService::directoryFor(1, 1, 'same'),
            ChunkedUploadService::directoryFor(1, 2, 'same')
        );
    }

    /** ومعرّف فارغ لا يُنتج مساراً يبتلع كل الرفعات. */
    public function test_an_empty_id_does_not_collapse_into_a_shared_directory(): void
    {
        $this->assertSame('invalid', ChunkedUploadService::sanitizeId('///'));
        $this->assertSame('invalid', ChunkedUploadService::sanitizeId(''));
    }

    // ------------------------------------------------- التنظيف

    /**
     * القطع المتروكة تُحذف: من يُغلق صفحته في منتصف الرفع يترك قطعاً لا شيء
     * يحذفها، فتتراكم حتى يمتلئ القرص ويسقط النظام كلّه لا الرفع وحده.
     */
    public function test_stale_uploads_are_pruned(): void
    {
        $directory = $this->directory('old-one');
        $this->putChunks($directory, ['A']);

        $this->travel(48)->hours();

        $this->assertSame(1, ChunkedUploadService::pruneStale(24));
        $this->assertFalse(Storage::disk('local')->exists($directory));
    }

    /** والحديثة تبقى — الرفع الجاري لا يُحذف من تحته. */
    public function test_a_fresh_upload_survives_pruning(): void
    {
        $directory = $this->directory('fresh');
        $this->putChunks($directory, ['A']);

        $this->assertSame(0, ChunkedUploadService::pruneStale(24));
        $this->assertTrue(Storage::disk('local')->exists($directory));
    }

    public function test_pruning_an_empty_root_is_safe(): void
    {
        $this->assertSame(0, ChunkedUploadService::pruneStale(24));
    }

    // ------------------------------------------------- الإلغاء

    public function test_discarding_removes_the_partial_upload(): void
    {
        $directory = $this->directory();
        $this->putChunks($directory, ['A', 'B']);

        ChunkedUploadService::discard($directory);

        $this->assertFalse(Storage::disk('local')->exists($directory));
    }
}
