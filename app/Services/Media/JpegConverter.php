<?php

namespace App\Services\Media;

/**
 * تحويل صورة إلى JPG بـ Imagick.
 *
 * السبب: تيليجرام يجلب صورة الحملة من رابطها، وsendPhoto يرفض صيغاً مثل
 * AVIF («failed to get HTTP URL content») — حملة Waz يوم 17/11/2026 فشلت
 * بها. وGD على الإنتاج بلا AVIF، أمّا Imagick فيقرؤها.
 *
 * الأمان: Imagick يقرأ صيغاً خطرة (SVG وPS وMVG…). فلا نترك له اكتشاف
 * الصيغة: الـ coder يُحدَّد صراحةً من قائمة سماح بنوع MIME المكتشَف من
 * المحتوى، وما عداها يُرفض قبل القراءة. والأبعاد تُفحص من الترويسة
 * (ping) قبل فكّ الصورة كلّها.
 */
class JpegConverter
{
    /** نوع MIME ← coder عند Imagick. ما ليس هنا لا يُحوَّل. */
    private const CODERS = [
        'image/avif' => 'AVIF',
        'image/heic' => 'HEIC',
        'image/heif' => 'HEIC',
        'image/webp' => 'WEBP',
        'image/bmp' => 'BMP',
        'image/x-ms-bmp' => 'BMP',
        'image/gif' => 'GIF',
        'image/tiff' => 'TIFF',
    ];

    /** 40 ميغابكسل: أكبر من أي صورة حملة معقولة، وأصغر ممّا يُتعب الخادم. */
    private const MAX_PIXELS = 40_000_000;

    private const QUALITY = 85;

    public static function supports(string $mimeType): bool
    {
        return array_key_exists(strtolower($mimeType), self::CODERS);
    }

    /**
     * @return string بايتات JPG
     *
     * @throws ImageConversionFailed
     */
    public function convert(string $contents, string $mimeType): string
    {
        $coder = self::CODERS[strtolower($mimeType)] ?? null;

        if ($coder === null) {
            throw new ImageConversionFailed("Unsupported image type: {$mimeType}");
        }

        if (!class_exists(\Imagick::class)) {
            throw new ImageConversionFailed('Imagick is not available.');
        }

        $source = new \Imagick();
        $output = null;

        try {
            $this->assertDimensions($contents, $coder);

            $source->setFormat($coder);
            $source->readImageBlob($contents);

            // GIF متحرّك: الإطار الأول وحده.
            $source->setIteratorIndex(0);
            $frame = $source->getImage();

            $frame->autoOrient();
            $frame->transformImageColorspace(\Imagick::COLORSPACE_SRGB);

            // الشفافية تصير بيضاء لا سوداء.
            $frame->setImageBackgroundColor('white');
            $output = $frame->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $frame->clear();

            $output->stripImage();
            // setFormat على القارئ يورَث للناتج ويغلب setImageFormat وحدها:
            // يخرج AVIF من جديد. فالاثنان معاً.
            $output->setFormat('jpeg');
            $output->setImageFormat('jpeg');
            $output->setImageCompressionQuality(self::QUALITY);

            $jpeg = $output->getImageBlob();
        } catch (\ImagickException $e) {
            throw new ImageConversionFailed($e->getMessage(), 0, $e);
        } finally {
            $source->clear();
            $output?->clear();
        }

        if (!str_starts_with($jpeg, "\xFF\xD8\xFF")) {
            throw new ImageConversionFailed('Conversion did not produce a JPEG.');
        }

        return $jpeg;
    }

    private function assertDimensions(string $contents, string $coder): void
    {
        $probe = new \Imagick();

        try {
            $probe->setFormat($coder);
            $probe->pingImageBlob($contents);
            $pixels = $probe->getImageWidth() * $probe->getImageHeight();
        } finally {
            $probe->clear();
        }

        if ($pixels <= 0 || $pixels > self::MAX_PIXELS) {
            throw new ImageConversionFailed('Image dimensions are out of range.');
        }
    }
}
