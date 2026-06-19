<?php

namespace App\Jobs;

use App\Helpers\MessagingWindowHelper;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Services\WhatsappService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class SendMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $organizationId,
        public string $uuid,
        public string $fileType,
        public string $fileName,
        public string $tempFilePath,
        public ?int $userId,
        public ?string $tempMessageId,
		public ?string $messageUUID
    ) {}

    public function handle(): void
    {
        $tempFullPath = Storage::disk('local')->path($this->tempFilePath);
        if (!is_readable($tempFullPath)) {
            return;
        }

        $fileContent = file_get_contents($tempFullPath);

        $storage = Setting::where('key', 'storage_system')->first()->value;
        $safeFileName = sanitize_filename_for_storage($this->fileName);

        if ($storage === 'local') {
            $location = 'local';
            $relativePath = 'public/' . uniqid() . '_' . $safeFileName;
            Storage::disk('local')->put($relativePath, $fileContent);
            $mediaFilePath = $relativePath;
            $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
        } elseif ($storage === 'aws') {
            $location = 'amazon';
            $s3Path = 'uploads/media/sent/' . $this->organizationId . '/' . uniqid() . '_' . $safeFileName;
            $contentType = whatsapp_media_content_type($this->fileType, $this->fileName);
            Storage::disk('s3')->put($s3Path, $fileContent, ['ContentType' => $contentType]);
            $mediaFilePath = Storage::disk('s3')->url($s3Path);
            $mediaUrl = $mediaFilePath;
        } else {
            Storage::disk('local')->delete($this->tempFilePath);
            return;
        }

        Storage::disk('local')->delete($this->tempFilePath);

        $organization = Organization::find($this->organizationId);
        if (!$organization) {
            return;
        }

        $contact = Contact::where('uuid', $this->uuid)
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->first();

        if (!$contact || !MessagingWindowHelper::isMessagingWindowOpen($contact)) {
            return;
        }

        $config = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $whatsappService = new WhatsappService(
            $accessToken,
            $apiVersion,
            $appId,
            $phoneNumberId,
            $wabaId,
            $this->organizationId
        );

        $whatsappService->sendMedia(
            $this->uuid,
            $this->fileType,
            $this->fileName,
            $mediaFilePath,
            $mediaUrl,
            $location,
            null,
            null,
            $this->userId,
            $this->tempMessageId,
			$this->messageUUID
        );
    }
}
