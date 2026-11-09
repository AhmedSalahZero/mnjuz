<?php

namespace App\Services\WebChat;

use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelManager;
use Illuminate\Http\UploadedFile;

/**
 * ما يُقبل من ملفات الزائر، ونوعه في النظام، وحدّ حجمه.
 */
final class WebChatUploads
{
    /** MIME المكتشَف من المحتوى → [نوع النظام، الامتداد المحفوظ]. */
    private const ALLOWED = [
        'image/jpeg' => ['image', 'jpg'],
        'image/png' => ['image', 'png'],
        'image/webp' => ['image', 'webp'],
        'image/gif' => ['image', 'gif'],
        'video/mp4' => ['video', 'mp4'],
        'audio/mpeg' => ['audio', 'mp3'],
        'audio/ogg' => ['audio', 'ogg'],
        'audio/mp4' => ['audio', 'm4a'],
        'audio/x-m4a' => ['audio', 'm4a'],
        'audio/aac' => ['audio', 'aac'],
        'application/pdf' => ['document', 'pdf'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['document', 'docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['document', 'xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['document', 'pptx'],
    ];

    /**
     * ملفات Office حاويات zip، وبعض نسخ libmagic تراها «application/zip».
     * نقبلها بامتدادها المعلَن فقط، وتُخدم بنوعها المكتشف — لا تُنفَّذ.
     */
    private const OFFICE_EXTENSIONS = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** @return array{type: string, mime_type: string, extension: string, size: int, file_name: string}|null */
    public static function classify(?UploadedFile $file): ?array
    {
        if (!$file || !$file->isValid()) {
            return null;
        }

        // من المحتوى نفسه بـ finfo، لا getMimeType(): تلك تُستبدل في بعض
        // الأغلفة (ملفات الاختبار مثلاً) بتخمين من الاسم، والاسم يكتبه الزائر.
        $mime = self::detectMime($file);
        $clientExtension = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($mime, ['application/zip', 'application/octet-stream'], true) && isset(self::OFFICE_EXTENSIONS[$clientExtension])) {
            $mime = self::OFFICE_EXTENSIONS[$clientExtension];
        }

        if (!isset(self::ALLOWED[$mime])) {
            return null;
        }

        [$type, $extension] = self::ALLOWED[$mime];

        return [
            'type' => $type,
            'mime_type' => $mime,
            'extension' => $extension,
            'size' => (int) $file->getSize(),
            'file_name' => self::displayName($file->getClientOriginalName(), $extension),
        ];
    }

    private static function detectMime(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path === false || !is_readable($path)) {
            return '';
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return strtolower(trim((string) $detected));
    }

    public static function limitFor(string $type): int
    {
        return (int) (app(ChannelManager::class)->capabilities(Channel::WEBCHAT)->mediaLimit($type) ?? 0);
    }

    public static function maxBytes(): int
    {
        return (int) max(app(ChannelManager::class)->capabilities(Channel::WEBCHAT)->mediaLimits ?: [0]);
    }

    /** @return array<int, string> */
    public static function acceptedMimeTypes(): array
    {
        return array_keys(self::ALLOWED);
    }

    /** اسم يُعرض للموظّف: بلا مسار ولا محارف تحكّم، وبالامتداد الحقيقي. */
    private static function displayName(string $name, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $stem = trim(pathinfo($name, PATHINFO_FILENAME));
        $stem = $stem === '' ? 'file' : mb_substr($stem, 0, 100);

        return $stem . '.' . $extension;
    }
}
