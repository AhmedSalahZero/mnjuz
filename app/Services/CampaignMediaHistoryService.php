<?php

namespace App\Services;

use App\Models\CampaignMediaHistory;

class CampaignMediaHistoryService
{
    /**
     * @return array<int, array{uuid: string, name: string, path: string, media_type: string, size: ?string, created_at: ?string}>
     */
    public function listForOrganization(int $organizationId, string $mediaType, int $limit = 30): array
    {
        return CampaignMediaHistory::query()
            ->where('organization_id', $organizationId)
            ->where('media_type', $mediaType)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['uuid', 'name', 'path', 'media_type', 'size', 'created_at'])
            ->map(fn (CampaignMediaHistory $item) => [
                'uuid' => $item->uuid,
                'name' => $item->name,
                'path' => $item->path,
                'media_type' => $item->media_type,
                'size' => $item->size,
                'created_at' => $item->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    public function record(
        int $organizationId,
        ?int $userId,
        string $mediaType,
        string $name,
        string $path,
        string $location,
        ?string $mimeType,
        ?string $size,
        ?int $chatMediaId = null,
    ): CampaignMediaHistory {
        $existing = CampaignMediaHistory::query()
            ->where('organization_id', $organizationId)
            ->where('path', $path)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            $existing->update([
                'name' => $name,
                'media_type' => $mediaType,
                'location' => $location,
                'mime_type' => $mimeType,
                'size' => $size,
                'chat_media_id' => $chatMediaId ?? $existing->chat_media_id,
                'created_by' => $userId ?? $existing->created_by,
                'created_at' => now(),
            ]);

            return $existing->fresh();
        }

        return CampaignMediaHistory::create([
            'organization_id' => $organizationId,
            'created_by' => $userId,
            'media_type' => $mediaType,
            'name' => $name,
            'path' => $path,
            'location' => $location,
            'mime_type' => $mimeType,
            'size' => $size,
            'chat_media_id' => $chatMediaId,
            'created_at' => now(),
        ]);
    }

    /**
     * الملف السابق كما يشير إليه النموذج: uuid أوّلاً ثم المسار.
     *
     * الواجهة صارت ترسل uuid — معرّف ثابت — بعد أن كان المسار وحده هو
     * المفتاح، وأيّ اختلاف حرف فيه (ترميز، مسافة، تغيّر شكل رابط التخزين)
     * يُسقط الاختيار برسالة «الملف لم يعد متاحاً» على ملفٍ موجود. والمسار
     * يبقى مقبولاً للصفحات المفتوحة قبل النشر.
     */
    public function findByReferenceForOrganization(int $organizationId, string $reference): ?CampaignMediaHistory
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        $byUuid = CampaignMediaHistory::query()
            ->where('organization_id', $organizationId)
            ->where('uuid', $reference)
            ->whereNull('deleted_at')
            ->first();

        return $byUuid ?: $this->findForOrganization($organizationId, $reference);
    }

    public function findForOrganization(int $organizationId, string $path): ?CampaignMediaHistory
    {
        return CampaignMediaHistory::query()
            ->where('organization_id', $organizationId)
            ->where('path', $path)
            ->whereNull('deleted_at')
            ->first();
    }

    public function deleteForOrganization(int $organizationId, string $uuid): bool
    {
        $item = CampaignMediaHistory::query()
            ->where('organization_id', $organizationId)
            ->where('uuid', $uuid)
            ->first();

        if (!$item) {
            return false;
        }

        $item->delete();

        return true;
    }
}
