<?php

namespace App\Jobs;

use App\Helpers\MessagingWindowHelper;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Services\VideoTranscodeService;
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

    public $timeout = 300;

    public function __construct(
        public int $organizationId,
        public string $uuid,
        public string $fileType,
        public string $fileName,
        public string $tempFilePath,
        public ?int $userId,
        public ?string $tempMessageId,
        public ?string $messageUUID,
        public ?string $caption = null,
    ) {}

    public function handle(): void
    {
        $tempFullPath = Storage::disk('local')->path($this->tempFilePath);
        if (!is_readable($tempFullPath)) {
            return;
        }

        // Proactively normalize video to a standard WhatsApp-compatible MP4 (remux + faststart)
        // so Meta accepts it on the first send and never returns error 131053 to the customer.
        $sendPath = $tempFullPath;
        $transcodedPath = null;
        $fileName = $this->fileName;

        if ($this->fileType === 'video') {
            $transcoder = new VideoTranscodeService();
            if ($transcoder->isAvailable()) {
                $normalized = $transcoder->transcodeForWhatsapp($tempFullPath);
                if ($normalized !== null) {
                    $sendPath = $normalized;
                    $transcodedPath = $normalized;
                    $fileName = pathinfo($this->fileName, PATHINFO_FILENAME) . '.mp4';
                }
            }
        }

        $fileContent = file_get_contents($sendPath);

        $storage = Setting::where('key', 'storage_system')->first()->value;
        $safeFileName = sanitize_filename_for_storage($fileName);

        if ($storage === 'local') {
            $location = 'local';
            $relativePath = 'public/' . uniqid() . '_' . $safeFileName;
            Storage::disk('local')->put($relativePath, $fileContent);
            $mediaFilePath = $relativePath;
            $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
        } elseif ($storage === 'aws') {
            $location = 'amazon';
            $s3Path = 'uploads/media/sent/' . $this->organizationId . '/' . uniqid() . '_' . $safeFileName;
            $contentType = $this->fileType === 'video' && $transcodedPath !== null
                ? 'video/mp4'
                : whatsapp_media_content_type($this->fileType, $fileName);
            Storage::disk('s3')->put($s3Path, $fileContent, ['ContentType' => $contentType]);
            $mediaFilePath = Storage::disk('s3')->url($s3Path);
            $mediaUrl = $mediaFilePath;
        } else {
            Storage::disk('local')->delete($this->tempFilePath);
            if ($transcodedPath !== null && is_file($transcodedPath)) {
                @unlink($transcodedPath);
            }
            return;
        }

        Storage::disk('local')->delete($this->tempFilePath);
        if ($transcodedPath !== null && is_file($transcodedPath)) {
            @unlink($transcodedPath);
        }

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

        $caption = $this->fileType === 'audio' ? null : $this->caption;

        $whatsappService->sendMedia(
            $this->uuid,
            $this->fileType,
            $fileName,
            $mediaFilePath,
            $mediaUrl,
            $location,
            $caption,
            null,
            $this->userId,
            $this->tempMessageId,
            $this->messageUUID
        );
    }
}
