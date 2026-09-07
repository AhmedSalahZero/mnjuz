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
    /**
     * سقف الطلب الفعلي: أصغر ما بين حدّ PHP وحدّ الطريق إليه.
     *
     * رفعُ post_max_size لا يرفع سقف Cloudflare أو أي وكيل أمامي — وقياسُ
     * الدفعة بحدّ PHP وحده كان يُنتج طلباً يمرّ من كل فحوصنا ثم يُقطَع في
     * الطريق بلا أثر.
     */
    public static function maxRequestBytes(): int
    {
        $configured = (int) config('chat.max_request_bytes', 0);

        if ($configured <= 0) {
            return self::phpMaxPostBytes();
        }

        return min(self::phpMaxPostBytes(), $configured);
    }

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

    /**
     * سقف الملف المجمَّع من قطع — حدّ النوع وحده بلا حدّ PHP.
     *
     * حدّ PHP يخصّ الطلب الواحد، والقطعة الواحدة لا تبلغه أصلاً (5MB). فقياس
     * الملف المجمَّع به يُبطل الغرض من التجزئة: مستندٌ من ستين ميغابايت تُقبل
     * قطعه كلّها ثم يُرفض بعد الدمج لأن upload_max_filesize أصغر منه — وهو
     * حدٌّ لم يمرّ به شيء.
     */
    public static function maxAssembledBytesForType(string $type): int
    {
        $byType = config('chat.max_upload_kb_by_type', []);

        $kb = match ($type) {
            // الصور تُضغط لاحقاً لحدّ واتساب، فلا سقف نوعي هنا.
            'image', 'gif' => null,
            'video' => (int) ($byType['video'] ?? self::VIDEO_MAX_KB),
            'audio' => (int) ($byType['audio'] ?? self::AUDIO_MAX_KB),
            'document' => (int) ($byType['document'] ?? self::DOCUMENT_MAX_KB),
            default => (int) config('chat.max_upload_kb', self::VIDEO_MAX_KB),
        };

        return $kb === null ? PHP_INT_MAX : $kb * 1024;
    }

    /**
     * الامتدادات المقبولة لكل نوع — مصدرٌ واحد للويب والتطبيق معاً.
     *
     * كان الجدول محبوساً داخل متحكّم واحد، فأي مسار آخر يحتاجه يُعيد كتابته
     * — ونسختان من قائمةٍ كهذه تفترقان عند أول إضافة.
     *
     * @return array<string, list<string>>
     */
    public static function extensionsByType(): array
    {
        return [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'heic', 'heif'],
            'video' => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', '3gp', 'mpeg', 'mpg'],
            'audio' => ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'flac', 'wma', 'amr', 'opus'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'odt', 'ods', 'odp'],
            'gif' => ['gif'],
        ];
    }

    /** نوع الوسيط من الامتداد، أو null إن لم يكن مقبولاً. */
    public static function typeForExtension(string $extension): ?string
    {
        $extension = strtolower(trim($extension));

        foreach (self::extensionsByType() as $type => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $type;
            }
        }

        return null;
    }

    /** @return list<string> كل الامتدادات المقبولة بلا تكرار. */
    public static function allowedExtensions(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::extensionsByType()))));
    }
}
