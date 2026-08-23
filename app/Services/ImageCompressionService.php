<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ImageCompressionService
{
    const IMAGE_MAX_BYTES = 5 * 1024 * 1024;
    const TARGET_BYTES    = 4800 * 1024; // 4.8 MB — safe margin below WhatsApp's 5MB limit

    /**
     * تهيئة صورة للإرسال عبر واتساب.
     *
     * ثلاث نتائج مقصودة ومتمايزة:
     *   • null  ⇒ الصورة مقبولة كما هي، تُرسَل بلا مساس (لا فقدان جودة).
     *   • مصفوفة ⇒ نسخة مُطبَّعة و/أو مضغوطة تُرسَل بدلاً منها.
     *   • false ⇒ تعذّر إنزالها تحت حدّ واتساب، فالرفض بيّن للمستخدم.
     *
     * كان الشرط الحجم وحده، فمرّت صور صغيرة بمساحة CMYK أو عمق 16 بت أو WebP
     * متحرّك — تُفتح عندنا بلا مشكلة وترفضها Meta بالخطأ 131053، فيقرأ
     * الموظّف رسالة عن «خصائص الصورة» لا يعرف ما يفعل بها.
     *
     * @return array{contents: string, extension: string, mime: string}|null|false
     */
    public static function prepareForWhatsapp(string $sourcePath, ?string $mime, int $size)
    {
        $tooLarge = $size > static::IMAGE_MAX_BYTES;
        $accepted = static::isAcceptedByWhatsapp($sourcePath, $mime);

        if (!$tooLarge && $accepted) {
            return null;
        }

        $result = $tooLarge
            ? static::compressToLimit($sourcePath, $mime)
            : static::normalizeForWhatsapp($sourcePath, $mime);

        return $result ?? false;
    }

    /**
     * هل تقبلها واتساب كما هي؟
     *
     * ترفض Meta بالخطأ 131053 كل ما خرج عن: JPEG/PNG بمساحة ألوان RGB أو
     * تدرّج رمادي و8 بت للقناة، أو WebP ثابت. والمرفوض شائع أكثر ممّا يبدو —
     * صورة CMYK خارجة من برنامج تصميم، أو PNG بعمق 16 بت، أو WebP متحرّك،
     * وكلّها تُفتح عندنا بلا مشكلة فلا يشكّ أحد فيها.
     *
     * الفحص قبل التحويل مقصود: إعادة الترميز تُفقد الشفافية وتُنقص الجودة،
     * فلا تُفرَض على صورة سليمة أصلاً.
     */
    public static function isAcceptedByWhatsapp(string $sourcePath, ?string $mime = null): bool
    {
        try {
            if (!extension_loaded('imagick')) {
                // بلا Imagick لا نستطيع قراءة مساحة اللون ولا العمق، فنكتفي
                // بفحص النوع: أضعف، لكنه لا يُعطّل الإرسال.
                return in_array(static::sniffFormat($sourcePath, $mime), ['jpeg', 'png', 'webp'], true);
            }

            $img = new \Imagick($sourcePath);

            try {
                $format = strtolower($img->getImageFormat());

                // رسائل الصور في واتساب تقبل JPEG و PNG وحدهما. WebP مخصّص
                // للملصقات، وإرساله صورةً تردّه Meta بالخطأ 131053.
                $accepted = ['jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

                if (!isset($accepted[$format])) {
                    return false;
                }

                // النوع المُعلَن يجب أن يطابق البايتات.
                //
                // ملفٌّ اسمه ‎.jpg‎ ومحتواه WebP شائع أكثر ممّا يبدو — أدوات
                // تحميل الصور من مواقع التواصل تفعل ذلك. ونحن نُعلن النوع من
                // الاسم لا من المحتوى، فنرسل «هذه JPEG» وبداخلها WebP، فترفضها
                // Meta بلا أن يفهم أحد لماذا: الصورة تُفتح في كل برنامج.
                if ($mime !== null && $mime !== '' && $accepted[$format] !== strtolower(trim($mime))) {
                    return false;
                }

                // الإطار الواحد شرط: WebP وGIF المتحرّكان مرفوضان.
                if ($img->getNumberImages() > 1) {
                    return false;
                }

                if ($img->getImageDepth() > 8) {
                    return false;
                }

                return in_array($img->getImageColorspace(), [
                    \Imagick::COLORSPACE_SRGB,
                    \Imagick::COLORSPACE_RGB,
                    \Imagick::COLORSPACE_GRAY,
                ], true);
            } finally {
                $img->destroy();
            }
        } catch (\Throwable $e) {
            // ملف لا يُفتح ليس صورة سليمة؛ نتركه للتطبيع ليحاول أو يفشل بوضوح.
            return false;
        }
    }

    /**
     * تحويل الصورة إلى ما تقبله واتساب: JPEG بمساحة sRGB و8 بت وإطار واحد.
     *
     * @return array{contents: string, extension: string, mime: string}|null
     */
    public static function normalizeForWhatsapp(string $sourcePath, ?string $mime = null): ?array
    {
        try {
            if (extension_loaded('imagick')) {
                return static::normalizeWithImagick($sourcePath);
            }

            // GD يفكّ الصورة إلى RGB بثمانية بتات دائماً، فإعادة ترميزها به
            // تُطبّعها ضمناً.
            if (extension_loaded('gd')) {
                return static::compressWithGd($sourcePath, $mime);
            }

            Log::warning('ImageCompressionService: neither Imagick nor GD is available.');

            return null;
        } catch (\Throwable $e) {
            Log::error('ImageCompressionService normalize error: ' . $e->getMessage(), ['file' => $sourcePath]);

            return null;
        }
    }

    /** @return array{contents: string, extension: string, mime: string}|null */
    private static function normalizeWithImagick(string $sourcePath): ?array
    {
        $img = new \Imagick($sourcePath);

        try {
            $img->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            $img->setImageDepth(8);

            if ($img->getImageAlphaChannel()) {
                $bg = new \Imagick();
                $bg->newImage($img->getImageWidth(), $img->getImageHeight(), new \ImagickPixel('white'), 'jpeg');
                $bg->compositeImage($img, \Imagick::COMPOSITE_OVER, 0, 0);
                $img->destroy();
                $img = $bg;
                $img->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
                $img->setImageDepth(8);
            }

            $img->setImageFormat('jpeg');
            // ملفّات ICC هي مصدر شائع لمساحات ألوان غريبة؛ نزعها مع بقيّة
            // البيانات الوصفية يجعل الناتج قابلاً للتنبّؤ.
            $img->stripImage();
            $img->setImageCompressionQuality(90);

            // getImageBlob المفردة لا getImagesBlob: الثانية تكتب الإطارات
            // كلّها، فصورة متحرّكة تخرج ملفاً متعدّد الإطارات ترفضه Meta من
            // جديد. المفردة تكتب الإطار الحالي — الأوّل — وهو المطلوب.
            $blob = $img->getImageBlob();

            if (strlen($blob) > static::TARGET_BYTES) {
                foreach ([85, 75, 65, 55, 45] as $quality) {
                    $img->setImageCompressionQuality($quality);
                    $blob = $img->getImageBlob();
                    if (strlen($blob) <= static::TARGET_BYTES) {
                        break;
                    }
                }
            }

            if (strlen($blob) > static::TARGET_BYTES) {
                return null;
            }

            return ['contents' => $blob, 'extension' => 'jpg', 'mime' => 'image/jpeg'];
        } finally {
            // $img يُعاد إسناده أثناء التسطيح؛ الإتلاف هنا يطال الأخير أيّاً كان.
            $img->destroy();
        }
    }

    /** نوع الصورة من توقيعها لا من امتدادها. */
    private static function sniffFormat(string $path, ?string $mime): string
    {
        $info = @getimagesize($path);

        if (is_array($info) && isset($info['mime'])) {
            $mime = $info['mime'];
        }

        return match (strtolower((string) $mime)) {
            'image/jpeg', 'image/jpg' => 'jpeg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'other',
        };
    }

    /**
     * Compress an image file to under TARGET_BYTES.
     *
     * Returns an array ['contents' => binary, 'extension' => 'jpg', 'mime' => 'image/jpeg']
     * on success, or null if the image cannot be reduced to the target size.
     */
    public static function compressToLimit(string $sourcePath, ?string $mime): ?array
    {
        try {
            if (extension_loaded('imagick')) {
                return static::compressWithImagick($sourcePath);
            }

            if (extension_loaded('gd')) {
                return static::compressWithGd($sourcePath, $mime);
            }

            Log::warning('ImageCompressionService: neither Imagick nor GD is available.');
            return null;
        } catch (\Throwable $e) {
            Log::error('ImageCompressionService error: ' . $e->getMessage(), [
                'file' => $sourcePath,
            ]);
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Imagick path
    // -------------------------------------------------------------------------

    private static function compressWithImagick(string $sourcePath): ?array
    {
        $img = new \Imagick($sourcePath);
        $img->setImageFormat('jpeg');
        $img->setImageCompressionQuality(85);

        // Flatten transparency onto white background (for PNG etc.)
        if ($img->getImageAlphaChannel()) {
            $bg = new \Imagick();
            $bg->newImage($img->getImageWidth(), $img->getImageHeight(), new \ImagickPixel('white'), 'jpeg');
            $bg->compositeImage($img, \Imagick::COMPOSITE_OVER, 0, 0);
            $img->destroy();
            $img = $bg;
        }

        $img->stripImage();

        // Phase 1: reduce quality in steps
        foreach ([85, 75, 65, 55, 45] as $quality) {
            $img->setImageCompressionQuality($quality);
            $blob = $img->getImagesBlob();
            if (strlen($blob) <= static::TARGET_BYTES) {
                $img->destroy();
                return ['contents' => $blob, 'extension' => 'jpg', 'mime' => 'image/jpeg'];
            }
        }

        // Phase 2: shrink dimensions while keeping quality at 45
        $img->setImageCompressionQuality(45);
        foreach ([2560, 2048, 1600, 1280, 1024, 800] as $maxDim) {
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            if ($w > $maxDim || $h > $maxDim) {
                $img->resizeImage($maxDim, $maxDim, \Imagick::FILTER_LANCZOS, 1, true);
            }
            $blob = $img->getImagesBlob();
            if (strlen($blob) <= static::TARGET_BYTES) {
                $img->destroy();
                return ['contents' => $blob, 'extension' => 'jpg', 'mime' => 'image/jpeg'];
            }
        }

        $img->destroy();
        return null;
    }

    // -------------------------------------------------------------------------
    // GD path
    // -------------------------------------------------------------------------

    private static function compressWithGd(string $sourcePath, ?string $mime): ?array
    {
        $src = static::gdLoad($sourcePath, $mime);
        if (!$src) {
            return null;
        }

        // Flatten transparency onto white background
        $w   = imagesx($src);
        $h   = imagesy($src);
        $out = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefill($out, 0, 0, $white);
        imagecopy($out, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);

        // Phase 1: reduce quality
        foreach ([85, 75, 65, 55, 45] as $quality) {
            $blob = static::gdToJpeg($out, $quality);
            if ($blob !== null && strlen($blob) <= static::TARGET_BYTES) {
                imagedestroy($out);
                return ['contents' => $blob, 'extension' => 'jpg', 'mime' => 'image/jpeg'];
            }
        }

        // Phase 2: shrink dimensions at quality 45
        foreach ([2560, 2048, 1600, 1280, 1024, 800] as $maxDim) {
            $cw = imagesx($out);
            $ch = imagesy($out);
            if ($cw > $maxDim || $ch > $maxDim) {
                [$nw, $nh] = static::scaleDimensions($cw, $ch, $maxDim);
                $resized = imagecreatetruecolor($nw, $nh);
                $bg      = imagecolorallocate($resized, 255, 255, 255);
                imagefill($resized, 0, 0, $bg);
                imagecopyresampled($resized, $out, 0, 0, 0, 0, $nw, $nh, $cw, $ch);
                imagedestroy($out);
                $out = $resized;
            }

            $blob = static::gdToJpeg($out, 45);
            if ($blob !== null && strlen($blob) <= static::TARGET_BYTES) {
                imagedestroy($out);
                return ['contents' => $blob, 'extension' => 'jpg', 'mime' => 'image/jpeg'];
            }
        }

        imagedestroy($out);
        return null;
    }

    /** @return \GdImage|false */
    private static function gdLoad(string $path, ?string $mime)
    {
        $type = $mime ?? mime_content_type($path) ?? '';

        if (str_contains($type, 'png')) {
            return @imagecreatefrompng($path);
        }
        if (str_contains($type, 'gif')) {
            return @imagecreatefromgif($path);
        }
        if (str_contains($type, 'webp')) {
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
        }
        // default: jpeg
        return @imagecreatefromjpeg($path);
    }

    private static function gdToJpeg($img, int $quality): ?string
    {
        ob_start();
        $ok = imagejpeg($img, null, $quality);
        $blob = ob_get_clean();
        return ($ok && $blob !== false) ? $blob : null;
    }

    private static function scaleDimensions(int $w, int $h, int $maxDim): array
    {
        if ($w >= $h) {
            return [$maxDim, (int) round($h * $maxDim / $w)];
        }
        return [(int) round($w * $maxDim / $h), $maxDim];
    }
}
