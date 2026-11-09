<?php

namespace App\Services\Messaging\Support;

use App\Models\Setting;
use App\Services\Messaging\Contracts\DownloadedMedia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * تخزين ملف وارد بنفس القواعد التي يستعملها مسار واتساب.
 *
 * نفس اسم الملف (بصمة المحتوى + الوقت)، ونفس المجلّدات، ونفس قراءة
 * إعداد storage_system. الاختلاف في أي منها يجعل ملفات القنوات الجديدة
 * تُخزَّن في مكان لا يعرفه مسار العرض أو النسخ الاحتياطي.
 */
class MediaStorage
{
    /**
     * @return array{path: string, location: string}|null
     */
    public static function store(DownloadedMedia $media, int $organizationId): ?array
    {
        $storage = Setting::where('key', 'storage_system')->first()?->value;

        $fileName = self::generateFilename($media->contents, $media->mimeType);

        if ($storage === 'local') {
            Storage::disk('local')->put('public/' . $fileName, $media->contents);

            return [
                'path' => rtrim(config('app.url'), '/') . '/media/public/' . $fileName,
                'location' => 'local',
            ];
        }

        if ($storage === 'aws') {
            $filePath = 'uploads/media/received/' . $organizationId . '/' . Str::random(40) . time();

            Storage::disk('s3')->put($filePath, $media->contents, [
                'ContentType' => $media->mimeType,
            ]);

            return [
                'path' => Storage::disk('s3')->url($filePath),
                'location' => 'amazon',
            ];
        }

        Log::warning('Channel media: unsupported storage system', [
            'storage' => $storage,
            'organization_id' => $organizationId,
        ]);

        return null;
    }

    /** نفس توليد الاسم في ProcessMediaDownloadJob حرفياً. */
    private static function generateFilename(string $contents, string $mimeType): string
    {
        $hash = sha1($contents);

        $subtype = explode('/', $mimeType)[1] ?? '';
        $subtype = trim(explode(';', $subtype)[0]);
        $extension = preg_replace('/[^a-zA-Z0-9]/', '', $subtype);

        if ($extension === '') {
            $extension = 'bin';
        }

        return "{$hash}_" . time() . ".{$extension}";
    }
}
