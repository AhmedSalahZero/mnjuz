<?php

namespace App\Jobs;

use App\Events\NewChatEvent;
use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatMedia;
use App\Models\Contact;
use App\Models\Setting;
use App\Services\WhatsappService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessMediaUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;

    protected $tempChatId;
    protected $tempMessageId;
    protected $filePath;
    protected $fileName;
    protected $mediaType;
    protected $contactUuid;
    protected $userId;
    protected $organizationId;
    protected $caption;

    public function __construct($tempChatId, $tempMessageId, $filePath, $fileName, $mediaType, $contactUuid, $userId, $organizationId, $caption = null)
    {
        $this->tempChatId = $tempChatId;
        $this->tempMessageId = $tempMessageId;
        $this->filePath = $filePath;
        $this->fileName = $fileName;
        $this->mediaType = $mediaType;
        $this->contactUuid = $contactUuid;
        $this->userId = $userId;
        $this->organizationId = $organizationId;
        $this->caption = $caption;
    }

    public function handle()
    {
        try {
            Log::info('Starting ProcessMediaUploadJob for chat ID: ' . $this->tempChatId);

            // Get the existing chat record
            $chat = Chat::find($this->tempChatId);
            if (!$chat) {
                Log::error('Chat record not found: ' . $this->tempChatId);
                return;
            }

            // Get organization config
            $organization = \App\Models\Organization::find($this->organizationId);
            $config = $organization->metadata ? json_decode($organization->metadata, true) : [];

            $accessToken = $config['whatsapp']['access_token'] ?? null;
            $apiVersion = config('graph.api_version');
            $appId = $config['whatsapp']['app_id'] ?? null;
            $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
            $wabaId = $config['whatsapp']['waba_id'] ?? null;

            $whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $this->organizationId);

            // Upload file to S3 if it exists locally
            $storage = Setting::where('key', 'storage_system')->first()->value ?? 'local';
            $mediaUrl = '';
            $location = 'local';

            if ($storage === 'aws') {
                // Move file from local to S3
                if (Storage::disk('local')->exists($this->filePath)) {
                    $fileContent = Storage::disk('local')->get($this->filePath);
                    $s3Path = 'uploads/media/sent/' . $this->organizationId . '/' . sanitize_filename_for_storage($this->fileName);

                    $uploaded = Storage::disk('s3')->put($s3Path, $fileContent, [
                        'ContentType' => mime_content_type(storage_path('app/' . $this->filePath))
                    ]);

                    if ($uploaded) {
                        $mediaUrl = 'https://' . env('AWS_BUCKET') . '.s3.' . env('AWS_DEFAULT_REGION') . '.amazonaws.com/' . $s3Path;
                        $location = 'amazon';

                        // Update media record with S3 URL
                        $chat->media->update([
                            'path' => $mediaUrl,
                            'location' => $location,
                            'updated_at' => now()
                        ]);

                        // Clean up local file
                        Storage::disk('local')->delete($this->filePath);
                    } else {
                        Log::error('Failed to upload file to S3: ' . $this->filePath);
                        return;
                    }
                } else {
                    Log::error('Local file not found: ' . $this->filePath);
                    return;
                }
            } else {
                // For local storage, just use the existing local URL
                $mediaUrl = $chat->media->path;
            }

            // Update metadata with final URL
            $metadata = json_decode($chat->metadata, true);
            $metadata[$this->mediaType]['link'] = $mediaUrl;
            $chat->metadata = json_encode($metadata);
            $chat->save();

            // Send media via WhatsApp with existing chat ID
            $response = $whatsappService->sendMedia(
                $this->contactUuid,
                $this->mediaType,
                $this->fileName,
                $chat->media->path, // Use the updated media path
                $mediaUrl,
                $location,
                $this->caption,
                null, // transcription
                $this->userId,
                $this->tempMessageId
            );

            if ($response && isset($response->success) && $response->success === true) {
                Log::info('ProcessMediaUploadJob completed successfully for chat ID: ' . $this->tempChatId);
            } else {
                Log::error('WhatsApp media send failed for chat ID: ' . $this->tempChatId, [
                    'response' => $response
                ]);
            }

        } catch (\Exception $e) {
            Log::error('ProcessMediaUploadJob failed for chat ID: ' . $this->tempChatId, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Mark chat as failed
            Chat::where('id', $this->tempChatId)->update([
                'status' => 'failed',
                'updated_at' => now()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('ProcessMediaUploadJob permanently failed for temp chat ID: ' . $this->tempChatId, [
            'error' => $exception->getMessage()
        ]);

        // Mark chat as failed
        Chat::where('id', $this->tempChatId)->update([
            'status' => 'failed',
            'updated_at' => now()
        ]);
    }
}
