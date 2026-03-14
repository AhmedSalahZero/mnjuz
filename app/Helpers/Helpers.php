<?php

use App\Events\NewChatEvent;

if (!function_exists('getApiLang')) {
    function getApiLang(): string
    {
        return app()->getLocale() ?? 'ar';
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
