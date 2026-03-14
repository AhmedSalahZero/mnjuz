<?php

use App\Events\NewChatEvent;

if (!function_exists('getApiLang')) {
    function getApiLang(): string
    {
        return app()->getLocale() ?? 'ar';
    }
}

/**
 * تحويل MIME type إلى قيمة مقبولة من واتساب.
 * واتساب يرفض مثلاً audio/x-m4a ويقبل فقط audio/mp4 للصوت، و video/mp4 للفيديو.
 */
if (!function_exists('whatsapp_acceptable_content_type')) {
    function whatsapp_acceptable_content_type(string $contentType, string $mediaType = ''): string
    {
        $contentType = trim($contentType);
        if ($contentType === '') {
            return $mediaType === 'audio' ? 'audio/mp4' : ($mediaType === 'video' ? 'video/mp4' : 'application/octet-stream');
        }
        $normalized = strtolower($contentType);
        $map = [
            'audio/x-m4a' => 'audio/mp4',
            'audio/m4a'   => 'audio/mp4',
            'video/x-m4v' => 'video/mp4',
        ];
        if (isset($map[$normalized])) {
            return $map[$normalized];
        }
        if ($mediaType === 'audio' && (str_starts_with($normalized, 'audio/') && !in_array($normalized, ['audio/ogg; codecs=opus', 'audio/mpeg', 'audio/amr', 'audio/mp4', 'audio/aac'], true))) {
            if (str_contains($normalized, 'm4a') || str_contains($normalized, 'mp4')) {
                return 'audio/mp4';
            }
        }
        if ($mediaType === 'video' && str_contains($normalized, 'm4a')) {
            return 'video/mp4';
        }
        return $contentType;
    }
}

/**
 * إرجاع Content-Type مناسب لرفع الملف إلى S3 بحيث يقبله واتساب عند جلب الرابط.
 * يعتمد على النوع المنطقي (audio/video/...) واسم الملف (للامتداد).
 */
if (!function_exists('whatsapp_media_content_type')) {
    function whatsapp_media_content_type(string $fileType, string $fileName): string
    {
        $ext = strtolower(primary_media_extension($fileName) ?: pathinfo($fileName, PATHINFO_EXTENSION) ?: '');

        if ($fileType === 'audio') {
            $audioMap = [
                'm4a' => 'audio/mp4', 'mp4' => 'audio/mp4', 'aac' => 'audio/aac', 'mp3' => 'audio/mpeg',
                'ogg' => 'audio/ogg; codecs=opus', 'opus' => 'audio/ogg; codecs=opus', 'amr' => 'audio/amr',
                'wav' => 'audio/mpeg', 'flac' => 'audio/mpeg', 'wma' => 'audio/mpeg',
            ];
            return $audioMap[$ext] ?? 'audio/mp4';
        }
        if ($fileType === 'video') {
            return in_array($ext, ['3gp', '3gpp'], true) ? 'video/3gpp' : 'video/mp4';
        }
        if ($fileType === 'image') {
            $imageMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            return $imageMap[$ext] ?? 'image/jpeg';
        }
        if ($fileType === 'document') {
            $docMap = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            return $docMap[$ext] ?? 'application/octet-stream';
        }
        return 'application/octet-stream';
    }
}

/**
 * امتدادات وسائط معروفة نعتبرها "الأولى" عند وجود امتداد مزدوج (مثل .m4a.mp4).
 */
if (!function_exists('primary_media_extension')) {
    function primary_media_extension(string $fileName): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION) ?: '');
        $base = pathinfo($fileName, PATHINFO_FILENAME);

        $knownFirstExtensions = [
            'm4a', 'aac', 'mp3', 'ogg', 'opus', 'amr', 'wav', 'flac', 'wma',
            'mp4', 'webm', 'mov', 'avi', 'mkv', '3gp', 'mpeg', 'mpg',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
        ];

        foreach ($knownFirstExtensions as $first) {
            $suffix = '.' . $first;
            if (strlen($base) > strlen($suffix) && strtolower(substr($base, -strlen($suffix))) === $suffix) {
                return $first;
            }
        }

        return $ext;
    }
}

/**
 * Sanitize a filename for use in storage paths (local/S3).
 * إذا كان الامتداد مزدوجاً (مثل .m4a.mp4) يُستخدَم الامتداد الأول فقط فيصل الاسم إلى .m4a.
 * Removes Unicode control/format characters (\p{C}) that trigger Flysystem CorruptedPathDetected.
 */
if (!function_exists('sanitize_filename_for_storage')) {
    function sanitize_filename_for_storage(string $fileName): string
    {
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $base = pathinfo($fileName, PATHINFO_FILENAME);

        $primaryExt = primary_media_extension($fileName);
        if ($primaryExt !== '' && $ext !== '' && strtolower($ext) !== $primaryExt) {
            $suffix = '.' . $primaryExt;
            if (strlen($base) > strlen($suffix) && strtolower(substr($base, -strlen($suffix))) === $suffix) {
                $base = substr($base, 0, -strlen($suffix));
                $ext = $primaryExt;
            }
        }

        $base = preg_replace('/\p{C}+/u', '', $base);
        $base = str_replace(['/', '\\', "\0"], '_', $base);
        $base = trim(preg_replace('/\s+/', ' ', $base));

        if ($base === '') {
            $base = 'file';
        }

        $ext = preg_replace('/\p{C}+/u', '', $ext);

        return $ext !== '' ? $base . '.' . $ext : $base;
    }
}

if (!function_exists('minimalChatValue')) {
    function minimalChatValue($chat): array
    {
        return (new NewChatEvent($chat, $chat->organization_id))->minimalChatValue($chat);
    }
}
