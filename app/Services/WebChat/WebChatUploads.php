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

    /** الزائر يختار حتى هذا العدد مرّة واحدة؛ كل ملف طلبٌ مستقلّ. */
    public const MAX_FILES_PER_SEND = 3;

    /**
     * ما يحتاجه الطلب فوق الملف نفسه (الحقول وحدود multipart). يُطرح من
     * post_max_size: ملفٌّ بحجمه تماماً يرفضه PHP مع ما حوله.
     */
    private const REQUEST_OVERHEAD = 64 * 1024;

    public static function limitFor(string $type): int
    {
        return self::limits()[$type] ?? 0;
    }

    public static function maxBytes(): int
    {
        return (int) max(self::limits() ?: [0]);
    }

    /**
     * الحدّ الفعلي لكل نوع — ما يُعلَن للـ widget وما يُفحص به الطلب.
     *
     * حدّ النوع وحده كذبة إن كان PHP لا يقبله: الملف يُرمى قبل أن يصلنا،
     * ويرى الزائر «حدث خطأ» بلا سبب. فيُقصّ بحدّ الخادم الفعلي.
     *
     * @return array<string, int>
     */
    public static function limits(): array
    {
        $types = app(ChannelManager::class)->capabilities(Channel::WEBCHAT)->mediaLimits;

        return self::effectiveLimits($types, self::serverMaxBytes());
    }

    /**
     * @param  array<string, int>  $typeLimits
     * @return array<string, int>
     */
    public static function effectiveLimits(array $typeLimits, ?int $serverMax): array
    {
        if ($serverMax === null) {
            return array_map('intval', $typeLimits);
        }

        return array_map(static fn ($limit) => min((int) $limit, $serverMax), $typeLimits);
    }

    /** أصغر ما يقبله الخادم لملفّ واحد، أو null إن لم يُحدَّ بشيء. */
    public static function serverMaxBytes(): ?int
    {
        $caps = array_filter([
            self::iniBytes((string) ini_get('upload_max_filesize')),
            ($post = self::iniBytes((string) ini_get('post_max_size'))) !== null ? max(0, $post - self::REQUEST_OVERHEAD) : null,
            ($mb = config('messaging.webchat_upload_max_mb')) !== null && is_numeric($mb) && (float) $mb > 0
                ? (int) round((float) $mb * 1024 * 1024)
                : null,
        ], static fn ($cap) => $cap !== null);

        return $caps === [] ? null : (int) min($caps);
    }

    /** «2M» و«512K» و«1G» كما في php.ini؛ 0 أو سالب أو فارغ = بلا حدّ (null). */
    public static function iniBytes(string $value): ?int
    {
        $value = strtolower(trim($value));

        if ($value === '' || !preg_match('/^(-?\d+)\s*([kmg]?)b?$/', $value, $match)) {
            return null;
        }

        $number = (int) $match[1];

        if ($number <= 0) {
            return null;
        }

        return $number * match ($match[2]) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
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
