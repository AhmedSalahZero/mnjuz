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
