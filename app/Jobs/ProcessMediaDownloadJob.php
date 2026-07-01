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

    // Keep below the queue retry_after (360s) so the job is never re-queued mid-run.
    public $timeout = 180;
    // WhatsApp media download URLs expire fast and S3/network hiccups happen,
    // so retry a few times before giving up. getMedia() re-fetches a fresh URL each attempt.
    public $tries = 4;
    public $backoff = [10, 30, 60];

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

        // If media already attached (e.g. a previous attempt succeeded), skip.
        if ($chat->media_id) {
            return;
        }

        // getMedia() throws a RuntimeException with the WhatsApp error details when the API
        // returns an error response (expired media, invalid token response, etc.), which
        // triggers a retry. It returns null only when the access token is missing (no retry needed).
        $media = $this->getMedia($mediaId, $organization);
        if (!is_array($media) || empty($media['url'])) {
            Log::warning('ProcessMediaDownloadJob: getMedia returned no URL (missing access token?)', [
                'chat_id'         => $this->chatId,
                'media_id'        => $mediaId,
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        $downloadedFile = $this->downloadMedia($media, $organization);
        if (!is_array($downloadedFile) || empty($downloadedFile['media_url'])) {
            throw new \RuntimeException('Unable to download media file for media_id ' . $mediaId);
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

        $this->broadcastChat($chat);

        WebhookHelper::triggerWebhookEvent(
            'message.received',
            ['data' => $this->message],
            $this->organizationId
        );
    }

    /**
     * After all retries are exhausted, still surface the message in the UI (without media)
     * so agents at least know something arrived instead of it silently disappearing.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessMediaDownloadJob permanently failed', [
            'chat_id' => $this->chatId,
            'organization_id' => $this->organizationId,
            'message_type' => $this->message['type'] ?? null,
            'error' => $exception->getMessage(),
        ]);

        $chat = Chat::find($this->chatId);
        if (!$chat || $chat->media_id) {
            return;
        }

        try {
            $this->broadcastChat($chat);

            WebhookHelper::triggerWebhookEvent(
                'message.received',
                ['data' => $this->message],
                $this->organizationId
            );
        } catch (\Throwable $e) {
            Log::error('ProcessMediaDownloadJob: failed() broadcast error', [
                'chat_id' => $this->chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function broadcastChat(Chat $chat): void
    {
        event(new \App\Events\NewChatEvent(
            $this->formatChatForEvent($chat, $this->isNewContact, $this->contactUuid),
            $this->organizationId,
            $this->isNewContact,
            false,
            true
        ));
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
            // No access token = not recoverable by retrying.
            return null;
        }

        $client = new Client();

        $requestOptions = [
            'headers' => [
                'Authorization' => 'Bearer ' . $metadata->whatsapp->access_token,
                'Content-Type' => 'application/json',
            ],
            // Bound the request so a hanging CDN (504) can't consume the whole job timeout.
            'connect_timeout' => 15,
            'timeout' => 90,
        ];

        // Let Guzzle exceptions bubble up so the job retries (handles expired URLs / network errors).
        $response = $client->request('GET', $mediaInfo['url'], $requestOptions);
        $fileContent = $response->getBody();

        $mimeType = $mediaInfo['mime_type'] ?? 'application/octet-stream';
        $fileName = $this->generateFilename($fileContent, $mimeType);
        $storage = Setting::where('key', 'storage_system')->first()->value;
        $mediaUrl = null;
        $location = null;

        // Storage failures (e.g. S3 timeout) should also bubble up to trigger a retry.
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
    }
    private function generateFilename($fileContent, $mimeType)
    {
        // Generate a unique filename based on the file content
        $hash = sha1($fileContent);

        // Derive a safe extension from the mime type.
        // Strips parameters like "audio/ogg; codecs=opus" and falls back to "bin".
        $subtype = explode('/', (string) $mimeType)[1] ?? '';
        $subtype = trim(explode(';', $subtype)[0]);
        $extension = preg_replace('/[^a-zA-Z0-9]/', '', $subtype);
        if ($extension === '') {
            $extension = 'bin';
        }

        return "{$hash}_" . time() . ".{$extension}";
    }

    private function getMedia($mediaId, Organization $organization): ?array
    {
        $metadata = json_decode($organization->metadata);

        if (empty($metadata) || empty($metadata->whatsapp->access_token)) {
            return null;
        }

        $client = new Client();

        $requestOptions = [
            'headers' => [
                'Authorization' => 'Bearer ' . $metadata->whatsapp->access_token,
                'Content-Type' => 'application/json',
            ],
            'connect_timeout' => 15,
            'timeout' => 30,
            // Don't throw on 4xx/5xx — we inspect the body ourselves below.
            'http_errors' => false,
        ];

        $response = $client->request('GET', "https://graph.facebook.com/v18.0/{$mediaId}", $requestOptions);
        $body     = $response->getBody()->getContents();
        $media    = json_decode($body, true);

        if (!is_array($media)) {
            return null;
        }

        // When WhatsApp returns an error (e.g. expired/deleted media, invalid token),
        // the response has no "url" field but has an "error" object.
        // Surface the actual WhatsApp reason in the exception message for easier diagnosis.
        if (isset($media['error'])) {
            $waMessage = $media['error']['message']   ?? 'unknown WhatsApp error';
            $waCode    = $media['error']['code']      ?? null;
            $waSubcode = $media['error']['error_subcode'] ?? null;
            throw new \RuntimeException(sprintf(
                'Unable to fetch media metadata for media_id %s — WhatsApp error %s%s: %s',
                $mediaId,
                $waCode,
                $waSubcode ? "/{$waSubcode}" : '',
                $waMessage
            ));
        }

        return $media;
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
