<?php

namespace App\Jobs;

use App\Helpers\DateTimeHelper;
use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatMedia;
use App\Models\Organization;
use App\Models\Setting;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessMediaDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 دقائق
    public $tries = 1;

    protected $chatId;
    protected $message;
    protected $organizationId;
    protected $isNewContact;
	protected $contactUuid;
    public function __construct($chatId, $message, $organizationId, $isNewContact = false, $contactUuid = null)
    {
        $this->chatId = $chatId;
        $this->message = $message;
        $this->organizationId = $organizationId;
        $this->isNewContact = $isNewContact;
		$this->contactUuid = $contactUuid;
    }
    
    public function handle()
    {
        $chat = Chat::find($this->chatId);
        if (!$chat) {
            return;
        }

        $organization = Organization::find($this->organizationId);
        if (!$organization) {
            Log::warning('ProcessMediaDownloadJob: organization not found', [
                'chat_id' => $this->chatId,
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        $type = $this->message['type'] ?? null;
        $mediaId = $type ? ($this->message[$type]['id'] ?? null) : null;
        if (!$type || !$mediaId) {
            Log::warning('ProcessMediaDownloadJob: missing media metadata', [
                'chat_id' => $this->chatId,
                'message_type' => $type,
            ]);

            return;
        }

        try {
            $media = $this->getMedia($mediaId, $organization);
            if (!is_array($media) || empty($media['url'])) {
                Log::warning('ProcessMediaDownloadJob: failed to fetch media metadata', [
                    'chat_id' => $this->chatId,
                    'media_id' => $mediaId,
                    'organization_id' => $this->organizationId,
                ]);

                return;
            }

            $downloadedFile = $this->downloadMedia($media, $organization);
            if (!is_array($downloadedFile) || empty($downloadedFile['media_url'])) {
                Log::warning('ProcessMediaDownloadJob: failed to download media file', [
                    'chat_id' => $this->chatId,
                    'media_id' => $mediaId,
                    'organization_id' => $this->organizationId,
                ]);

                return;
            }

            $chatMedia = ChatMedia::create([
                'name' => $type === 'document' && isset($this->message[$type]['filename'])
                    ? $this->message[$type]['filename']
                    : 'N/A',
                'path' => $downloadedFile['media_url'],
                'type' => $media['mime_type'] ?? 'application/octet-stream',
                'size' => $media['file_size'] ?? null,
                'location' => $downloadedFile['location'],
                'created_at' => now(),
            ]);
            $chat->update(['media_id' => $chatMedia->id]);

            event(new \App\Events\NewChatEvent(
                $this->formatChatForEvent($chat, $this->isNewContact, $this->contactUuid),
                $this->organizationId,
                $this->isNewContact,
                false,
                true
            ));

            WebhookHelper::triggerWebhookEvent(
                'message.received',
                ['data' => $this->message],
                $this->organizationId
            );
        } catch (\Throwable $e) {
            Log::error('ProcessMediaDownloadJob failed', [
                'chat_id' => $this->chatId,
                'media_id' => $mediaId,
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function downloadMedia($mediaInfo, Organization $organization): ?array
    {
        if (! is_array($mediaInfo) || empty($mediaInfo['url'])) {
            Log::warning('ProcessMediaDownloadJob: invalid media metadata for download', [
                'organization_id' => $organization->id,
            ]);

            return null;
        }

        $metadata = json_decode($organization->metadata);

        if (empty($metadata) || empty($metadata->whatsapp->access_token)) {
            return null;
        }

        try {
            $client = new Client();

            $requestOptions = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $metadata->whatsapp->access_token,
                    'Content-Type' => 'application/json',
                ],
            ];

            $response = $client->request('GET', $mediaInfo['url'], $requestOptions);
            $fileContent = $response->getBody();
            $mimeType = $mediaInfo['mime_type'] ?? 'application/octet-stream';
            $fileName = $this->generateFilename($fileContent, $mimeType);
            $storage = Setting::where('key', 'storage_system')->first()->value;
            $mediaUrl = null;
            $location = null;

            if ($storage === 'local') {
                $location = 'local';
                Storage::disk('local')->put('public/' . $fileName, $fileContent);
                $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . 'public/' . $fileName;
            } elseif ($storage === 'aws') {
                $location = 'amazon';
                $filePath = 'uploads/media/received/' . $organization->id . '/' . Str::random(40) . time();
                Storage::disk('s3')->put($filePath, $fileContent, [
                    'ContentType' => $mimeType,
                ]);
                $mediaUrl = Storage::disk('s3')->url($filePath);
            }

            if ($mediaUrl === null || $location === null) {
                Log::warning('ProcessMediaDownloadJob: unsupported storage system', [
                    'storage' => $storage,
                    'organization_id' => $organization->id,
                ]);

                return null;
            }

            return [
                'media_url' => $mediaUrl,
                'location' => $location,
            ];
        } catch (\Throwable $e) {
            Log::error('ProcessMediaDownloadJob: media download failed', [
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
    private function generateFilename($fileContent, $mimeType)
    {
        // Generate a unique filename based on the file content
        $hash = sha1($fileContent);

        // Get the file extension from the media type
        $extension = explode('/', $mimeType)[1];

        // Combine the hash, timestamp, and extension to create a unique filename
        $filename = "{$hash}_" . time() . ".{$extension}";

        return $filename;
    }

    private function getMedia($mediaId, Organization $organization): ?array
    {
        $metadata = json_decode($organization->metadata);

        if (empty($metadata) || empty($metadata->whatsapp->access_token)) {
            return null;
        }

        $client = new Client();

        try {
            $requestOptions = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $metadata->whatsapp->access_token,
                    'Content-Type' => 'application/json',
                ],
            ];

            $response = $client->request('GET', "https://graph.facebook.com/v18.0/{$mediaId}", $requestOptions);
            $media = json_decode($response->getBody()->getContents(), true);

            return is_array($media) ? $media : null;
        } catch (\Throwable $e) {
            Log::warning('ProcessMediaDownloadJob: media metadata request failed', [
                'media_id' => $mediaId,
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
	private function formatChatForEvent($chat, bool $isNewContact = false, $contactUuid = null)
    {
        $chatLog = ChatLog::where('entity_id', $chat->id)
            ->where('entity_type', 'chat')
            ->first();

        return [[
            'is_new_contact' => $isNewContact,
			'contact_uuid' => $contactUuid,
            'type' => 'chat',
            'value' => $chatLog->relatedEntities ?? $chat,
        ]];
    }

}
