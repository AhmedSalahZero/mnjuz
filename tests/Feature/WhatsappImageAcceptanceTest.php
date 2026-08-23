<?php

namespace Tests\Feature;

use App\Services\ImageCompressionService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * تهيئة الصور لما تقبله واتساب.
 *
 * عطلٌ وقع على الإنتاج: رفع موظّف صورة فردّت Meta بالخطأ 131053 — «Image is
 * invalid… supported are JPG/JPEG, RGB/RGBA, 8 bit/channels, PNG…, WebP
 * (static only)». والصورة تُفتح عندنا بلا مشكلة، فلا يعرف الموظّف ما العيب.
 *
 * السبب أن التهيئة كانت مشروطة بالحجم وحده: ما تجاوز خمسة ميغابايت يُضغط،
 * وما دونه يمرّ كما هو. فصورة CMYK خارجة من برنامج تصميم، أو PNG بعمق 16 بت،
 * أو WebP متحرّك — كلّها تحت الحدّ بكثير وكلّها مرفوضة.
 *
 * والاختبارات تحرس الطرفين: أن غير المقبول يُطبَّع، وأن المقبول **لا يُمَسّ** —
 * إعادة ترميز صورة سليمة تُفقد الشفافية وتُنقص الجودة بلا سبب.
 */
class WhatsappImageAcceptanceTest extends TestCase
{
    /** @var array<int, string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('الفحص يحتاج Imagick لقراءة مساحة اللون والعمق.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function path(string $extension): string
    {
        $file = sys_get_temp_dir() . '/wa_img_' . Str::random(8) . '.' . $extension;
        $this->created[] = $file;

        return $file;
    }

    /** صورة بمواصفات محدّدة عبر Imagick مباشرةً. */
    private function image(string $extension, callable $configure): string
    {
        $path = $this->path($extension);

        $img = new \Imagick();
        $img->newPseudoImage(200, 200, 'gradient:red-blue');
        $configure($img);
        $img->writeImage($path);
        $img->destroy();

        return $path;
    }

    /**
     * ملف بصيغة محدّدة وامتداد قد يخالفها.
     *
     * writeImage تشتقّ الصيغة من الامتداد فتتجاهل setImageFormat — وهو ما
     * يجعل كتابة «WebP باسم ‎.jpg‎» مستحيلة عبرها. الكتابة من البايتات مباشرةً
     * تُنتج الحالة الحقيقية التي وقعت.
     */
    private function fileWithFormat(string $format, string $extension): string
    {
        $path = $this->path($extension);

        $img = new \Imagick();
        $img->newPseudoImage(300, 300, 'gradient:red-blue');
        // sRGB وثمانية بتات صراحةً: التدرّج يُكتب بعمق 16 افتراضياً، فيُرفَض
        // الملف لعمقه لا لنوعه — فينجح الاختبار وهو لا يقيس ما جاء لأجله.
        $img->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        $img->setImageDepth(8);
        $img->setImageFormat($format);
        file_put_contents($path, $img->getImageBlob());
        $img->destroy();

        return $path;
    }

    private function describe(string $blob): array
    {
        $img = new \Imagick();
        $img->readImageBlob($blob);

        $described = [
            'format' => strtolower($img->getImageFormat()),
            'depth' => $img->getImageDepth(),
            'frames' => $img->getNumberImages(),
            'colorspace' => $img->getImageColorspace(),
        ];

        $img->destroy();

        return $described;
    }

    private function prepare(string $path)
    {
        return ImageCompressionService::prepareForWhatsapp($path, mime_content_type($path), filesize($path));
    }

    // ------------------------------------------- المرفوض يُطبَّع

    /** الحالة التي وقعت: مساحة CMYK. */
    public function test_a_cmyk_image_is_converted_to_srgb(): void
    {
        $path = $this->image('jpg', fn ($img) => $img->transformImageColorspace(\Imagick::COLORSPACE_CMYK));

        $this->assertFalse(ImageCompressionService::isAcceptedByWhatsapp($path), 'CMYK يجب أن تُرفض.');

        $prepared = $this->prepare($path);

        $this->assertIsArray($prepared, 'CMYK لم تُطبَّع — سترفضها Meta بالخطأ 131053.');
        $this->assertSame(\Imagick::COLORSPACE_SRGB, $this->describe($prepared['contents'])['colorspace']);
    }

    /** وعمق 16 بت للقناة. */
    public function test_a_sixteen_bit_image_is_reduced_to_eight(): void
    {
        $path = $this->image('png', fn ($img) => $img->setImageDepth(16));

        $this->assertFalse(ImageCompressionService::isAcceptedByWhatsapp($path));

        $described = $this->describe($this->prepare($path)['contents']);

        $this->assertLessThanOrEqual(8, $described['depth']);
    }

    /** والمتحرّك: واتساب تقبل WebP الثابت وحده. */
    public function test_an_animated_image_is_flattened_to_one_frame(): void
    {
        $path = $this->path('webp');

        $img = new \Imagick();
        $img->newPseudoImage(100, 100, 'xc:red');
        $img->newPseudoImage(100, 100, 'xc:blue');
        $img->writeImages($path, true);
        $img->destroy();

        if ((new \Imagick($path))->getNumberImages() < 2) {
            $this->markTestSkipped('تعذّر إنشاء صورة متحرّكة في هذه البيئة.');
        }

        $this->assertFalse(ImageCompressionService::isAcceptedByWhatsapp($path));

        $this->assertSame(1, $this->describe($this->prepare($path)['contents'])['frames']);
    }

    /** والناتج دائماً بصيغة تقبلها Meta. */
    public function test_the_normalized_output_is_a_jpeg(): void
    {
        $path = $this->image('jpg', fn ($img) => $img->transformImageColorspace(\Imagick::COLORSPACE_CMYK));
        $prepared = $this->prepare($path);

        $this->assertSame('jpeg', $this->describe($prepared['contents'])['format']);
        $this->assertSame('jpg', $prepared['extension']);
        $this->assertSame('image/jpeg', $prepared['mime']);
    }

    // ----------------------------- الامتداد يكذب على المحتوى

    /**
     * الحالة التي أوقعت العطل على الديف: ملف اسمه ‎.jpg‎ ونوعه المسجَّل
     * image/jpeg، ومحتواه WebP. أدوات تحميل الصور من مواقع التواصل تُنتجه
     * كثيراً، ويُفتح في كل برنامج فلا يشكّ فيه أحد — ونحن نُعلن نوعه من اسمه
     * فنقول لـMeta «هذه JPEG» وبداخلها WebP.
     */
    public function test_a_webp_disguised_as_jpeg_is_normalized(): void
    {
        $path = $this->fileWithFormat('webp', 'jpg');

        $this->assertSame('WEBP', (new \Imagick($path))->getImageFormat(), 'العيّنة ليست WebP.');

        $this->assertFalse(
            ImageCompressionService::isAcceptedByWhatsapp($path, 'image/jpeg'),
            'ملف WebP مُعلَن JPEG مرّ كما هو — سترفضه Meta بالخطأ 131053.'
        );

        $prepared = ImageCompressionService::prepareForWhatsapp($path, 'image/jpeg', filesize($path));

        $this->assertIsArray($prepared);
        $this->assertSame('jpeg', $this->describe($prepared['contents'])['format']);
        $this->assertSame('image/jpeg', $prepared['mime']);
    }

    /**
     * وWebP الصريح كذلك: رسائل الصور تقبل JPEG و PNG وحدهما، وWebP للملصقات.
     */
    public function test_a_plain_webp_is_not_accepted_as_an_image(): void
    {
        $path = $this->fileWithFormat('webp', 'webp');

        $this->assertFalse(ImageCompressionService::isAcceptedByWhatsapp($path, 'image/webp'));
        $this->assertIsArray(ImageCompressionService::prepareForWhatsapp($path, 'image/webp', filesize($path)));
    }

    /** والعكس: PNG حقيقي مُعلَن JPEG يُطبَّع كذلك. */
    public function test_a_png_disguised_as_jpeg_is_normalized(): void
    {
        $path = $this->fileWithFormat('png', 'jpg');

        // العيّنة سليمة في كل شيء عدا التطابق: PNG حقيقي بعمق 8 و sRGB.
        // فالرفض هنا لا يقع إلّا بسبب النوع المُعلَن.
        $this->assertTrue(
            ImageCompressionService::isAcceptedByWhatsapp($path, 'image/png'),
            'العيّنة نفسها مرفوضة لسبب آخر — الاختبار لا يقيس التطابق.'
        );

        $this->assertFalse(ImageCompressionService::isAcceptedByWhatsapp($path, 'image/jpeg'));
    }

    /** والمطابق لا يُمَسّ: PNG حقيقي مُعلَن PNG. */
    public function test_a_matching_declaration_passes(): void
    {
        $path = $this->image('png', function ($img) {
            $img->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            $img->setImageDepth(8);
        });

        $this->assertTrue(ImageCompressionService::isAcceptedByWhatsapp($path, 'image/png'));
    }

    // ------------------------------------------ المقبول لا يُمَسّ

    /**
     * الطرف الآخر، وهو ما يمنع الإصلاح من أن يصير ضرراً: صورة سليمة تُرسَل
     * كما هي. إعادة ترميزها تُفقد الشفافية وتُنقص الجودة بلا سبب.
     */
    public function test_a_valid_jpeg_is_sent_untouched(): void
    {
        $path = $this->image('jpg', function ($img) {
            $img->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            $img->setImageDepth(8);
        });

        $this->assertTrue(ImageCompressionService::isAcceptedByWhatsapp($path));
        $this->assertNull($this->prepare($path), 'صورة سليمة أُعيد ترميزها بلا داعٍ.');
    }

    public function test_a_valid_png_is_sent_untouched(): void
    {
        $path = $this->image('png', function ($img) {
            $img->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            $img->setImageDepth(8);
        });

        $this->assertNull($this->prepare($path));
    }

    /** والرمادي مقبول كذلك — لا نُطبّع ما لا يحتاج. */
    public function test_a_grayscale_image_is_accepted(): void
    {
        $path = $this->image('jpg', function ($img) {
            $img->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
            $img->setImageDepth(8);
        });

        $this->assertTrue(ImageCompressionService::isAcceptedByWhatsapp($path));
    }

    // ------------------------------------------------- الحجم

    /** الحجم ما زال يُعالَج: الشرط صار «أو» لا بديلاً. */
    public function test_an_oversized_image_is_still_compressed(): void
    {
        $path = $this->path('jpg');

        $img = new \Imagick();
        $img->newPseudoImage(6000, 6000, 'plasma:');
        $img->setImageFormat('jpeg');
        $img->setImageCompressionQuality(100);
        $img->writeImage($path);
        $img->destroy();

        if (filesize($path) <= ImageCompressionService::IMAGE_MAX_BYTES) {
            $this->markTestSkipped('تعذّر إنشاء صورة تتجاوز الحدّ في هذه البيئة.');
        }

        $prepared = $this->prepare($path);

        $this->assertIsArray($prepared);
        $this->assertLessThanOrEqual(ImageCompressionService::IMAGE_MAX_BYTES, strlen($prepared['contents']));
    }

    // ------------------------------------------------- المتانة

    /** ملف تالف لا يُرسَل ولا يُسقط الطلب: false تعني رفضاً مفهوماً. */
    public function test_a_corrupt_file_is_refused_not_crashed(): void
    {
        $path = $this->path('jpg');
        file_put_contents($path, 'ليست صورة');

        $this->assertFalse(ImageCompressionService::isAcceptedByWhatsapp($path));
        $this->assertFalse($this->prepare($path));
    }

    // ------------------------------------------------- الأسلاك

    /** كلا مساري الإرسال يهيّئ كل صورة لا الكبيرة وحدها. */
    public function test_both_send_paths_prepare_every_image(): void
    {
        $source = file_get_contents(base_path('app/Services/ChatService.php'));

        $this->assertSame(
            2,
            substr_count($source, 'ImageCompressionService::prepareForWhatsapp'),
            'أحد مسارَي الإرسال ما زال يهيّئ الصور الكبيرة وحدها.'
        );

        $this->assertStringNotContainsString(
            "\$fileType === 'image' && \$file->getSize() > ImageCompressionService::IMAGE_MAX_BYTES",
            $source,
            'الشرط القديم عاد: الحجم وحده لا يكفي.'
        );
    }
}
