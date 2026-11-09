<?php

namespace App\Services\Carousel;

use App\Models\ChatMedia;
use App\Models\Setting;
use App\Services\CampaignMediaHistoryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * حفظ ملف بطاقة في تخزين المنشأة، بنفس قواعد ترويسة الحملة:
 * local → قرص local/public ورابط app.url/media، وaws → S3 تحت
 * uploads/media/sent/{org}. ثم صفّ ChatMedia، وسجلّ «الملفات السابقة» إن طُلب.
 */
class CarouselMediaStorer
{
    public function __construct(private CampaignMediaHistoryService $history)
    {
    }

    /**
     * @return array{url: string, location: string, chat_media_id: int, name: string, mime: string, size: int}
     */
    public function store(UploadedFile $file, int $organizationId, ?int $userId, string $mediaType, bool $recordHistory): array
    {
        $storage = Setting::where('key', 'storage_system')->value('value');
        $name = $file->getClientOriginalName();
        // chat_media.type وsize لا يقبلان null.
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $size = (int) $file->getSize();

        if ($storage === 'aws') {
            $location = 'amazon';
            $path = $file->store('uploads/media/sent/' . $organizationId, 's3');

            if (empty($path)) {
                throw new \RuntimeException('Failed to upload file to S3 storage');
            }

            $url = Storage::disk('s3')->url($path);
        } elseif ($storage === 'local') {
            $location = 'local';
            $path = Storage::disk('local')->put('public', $file);

            if (empty($path)) {
                throw new \RuntimeException('Failed to store file');
            }

            $url = rtrim(config('app.url'), '/') . '/media/' . ltrim($path, '/');
        } else {
            throw new \RuntimeException('Unsupported storage system');
        }

        $chatMedia = new ChatMedia;
        $chatMedia->name = $name;
        $chatMedia->path = $url;
        $chatMedia->location = $location;
        $chatMedia->type = $mime;
        $chatMedia->size = $size;
        $chatMedia->created_at = now();
        $chatMedia->save();

        if ($recordHistory) {
            $this->history->record(
                $organizationId,
                $userId,
                strtoupper($mediaType),
                $name,
                $url,
                $location,
                $mime,
                (string) $size,
                $chatMedia->id,
            );
        }

        return [
            'url' => $url,
            'location' => $location,
            'chat_media_id' => $chatMedia->id,
            'name' => $name,
            'mime' => $mime,
            'size' => $size,
        ];
    }
}
