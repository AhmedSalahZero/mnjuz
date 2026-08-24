<?php

namespace App\Helpers;

/**
 * حدود رفع الوسائط — نفس منطق الداشبورد (ChatForm.vue + ImageCompressionService).
 *
 * الصور: لا سقف نوعي عند الرفع (يُضغط الخادم لحدّ واتساب 5MB). الفيديو/الصوت:
 * 16MB. المستندات: 100MB. السقف الفعلي دائماً لا يتجاوز إعدادات PHP.
 */
class ChatMediaUploadHelper
{
    /** حدّ واتساب للصور بعد الضغط — ImageCompressionService::IMAGE_MAX_BYTES */
    public const IMAGE_WHATSAPP_BYTES = 5 * 1024 * 1024;

    public const VIDEO_MAX_KB = 16384;   // 16 MB
    public const AUDIO_MAX_KB = 16384;   // 16 MB
    public const DOCUMENT_MAX_KB = 102400; // 100 MB

    /**
     * أصغر الحدّين: upload_max_filesize يحكم الملف، وpost_max_size يحكم الطلب
     * كلّه — فالفعّال هو الأصغر.
     */
    public static function phpMaxUploadBytes(): int
    {
        $toBytes = static function (string $value): int {
            $value = trim($value);
            if ($value === '' || $value === '-1') {
                return PHP_INT_MAX;
            }

            $unit = strtolower(substr($value, -1));
            $number = (int) $value;

            return match ($unit) {
                'g' => $number * 1024 * 1024 * 1024,
                'm' => $number * 1024 * 1024,
                'k' => $number * 1024,
                default => $number,
            };
        };

        return min(
            $toBytes((string) ini_get('upload_max_filesize')),
            $toBytes((string) ini_get('post_max_size'))
        );
    }

    /**
     * سقف الطلب الواحد — لا سقف الملف الواحد.
     *
     * post_max_size يحكم الحمولة كلّها، وupload_max_filesize يحكم كل ملف على
     * حدة. ورفع عدّة ملفات معاً يذهب في طلب واحد، فمجموعها هو ما يُقاس.
     *
     * كان المعروض للواجهة أصغرَهما وحده، فتُفحص الملفات فرادى ويمرّ مجموعها
     * بلا فحص: ثلاثة ملفات كلٌّ منها مقبول تصير حمولةً يرفضها الخادم — ويقف
     * الرفع عند ٢٪ بلا رسالة، لأن الرفض يقع قبل أن يبلغ PHP.
     */
    public static function phpMaxPostBytes(): int
    {
        $value = trim((string) ini_get('post_max_size'));

        if ($value === '' || $value === '-1' || $value === '0') {
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

    /**
     * الحدّ الفعلي بالبايت لنوع الوسيط — min(حدّ النوع، حدّ PHP).
     *
     * الصور وGIF: حدّ PHP فقط (الضغط يتولى حدّ واتساب لاحقاً).
     */
    public static function maxUploadBytesForType(string $type): int
    {
        $phpLimit = self::phpMaxUploadBytes();
        $byType = config('chat.max_upload_kb_by_type', []);

        $configKb = match ($type) {
            'image', 'gif' => null,
            'video' => (int) ($byType['video'] ?? self::VIDEO_MAX_KB),
            'audio' => (int) ($byType['audio'] ?? self::AUDIO_MAX_KB),
            'document' => (int) ($byType['document'] ?? self::DOCUMENT_MAX_KB),
            default => (int) config('chat.max_upload_kb', self::VIDEO_MAX_KB),
        };

        if ($configKb === null) {
            return $phpLimit;
        }

        return min($configKb * 1024, $phpLimit);
    }

    public static function maxUploadKbForType(string $type): int
    {
        return (int) floor(self::maxUploadBytesForType($type) / 1024);
    }

    public static function humanMaxSizeForType(string $type): string
    {
        $mb = (int) round(self::maxUploadBytesForType($type) / (1024 * 1024));

        return $mb . ' MB';
    }
}
