<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ImageCompressionService
{
    const IMAGE_MAX_BYTES = 5 * 1024 * 1024;
    const TARGET_BYTES    = 4800 * 1024; // 4.8 MB — safe margin below WhatsApp's 5MB limit

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
