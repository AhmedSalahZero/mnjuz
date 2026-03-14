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
use Illuminate\Support\Facades\Response;
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
        $type = $this->message['type'];
        $mediaId = $this->message[$type]['id'];
		try{
        $media = $this->getMedia($mediaId, $organization);
        
        $downloadedFile = $this->downloadMedia($media, $organization);
        $chatMedia = ChatMedia::create([
            'name' => $type === 'document' && isset($this->message[$type]['filename'])
                ? $this->message[$type]['filename']
                : 'N/A',
            'path' => $downloadedFile['media_url'],
            'type' => $media['mime_type'],
            'size' => $media['file_size'],
            'location' => $downloadedFile['location'],
            'created_at' =>  now(),
        ]);
        $chat->update(['media_id' => $chatMedia->id]);
        
        
        event(new \App\Events\NewChatEvent(
            $this->formatChatForEvent($chat, $this->isNewContact, $this->contactUuid),
            $this->organizationId,
            $this->isNewContact,
			false,
			true
        ));

        // ✅ Webhook
        WebhookHelper::triggerWebhookEvent(
            'message.received',
            ['data' => $this->message],
            $this->organizationId
        );
		}
		catch (\Exception $e){
			return;
		}
                    
    }
    private function downloadMedia($mediaInfo, Organization $organization)
    {
        $metadata = json_decode($organization->metadata);

        if (empty($metadata) || empty($metadata->whatsapp->access_token)) {
            return $this->forbiddenResponse();
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
            $mimeType = $mediaInfo['mime_type'] ?? 'application/octet-stream'; // Default fallback
            $fileName = $this->generateFilename($fileContent, $mediaInfo['mime_type']);
            $storage = Setting::where('key', 'storage_system')->first()->value;

            if ($storage === 'local') {
                $t = microtime(true);
                $location = 'local';
                $file = Storage::disk('local')->put('public/' . $fileName, $fileContent);
                $mediaFilePath = $file;
                $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . 'public/' . $fileName;
            } elseif ($storage === 'aws') {
                $t = microtime(true);
                $location = 'amazon';
                $filePath = 'uploads/media/received/'  . $organization->id . '/' . Str::random(40) . time();
                $file = Storage::disk('s3')->put($filePath, $fileContent, [
                    'ContentType' => $mimeType
                ]);
                $mediaUrl = Storage::disk('s3')->url($filePath);

            }

            $mediaData = [
                'media_url' => $mediaUrl,
                'location' => $location,
            ];
    
            return $mediaData;
        } catch (\Exception $e) {
            Log::error("Error processing webhook: " . $e->getMessage());
            return Response::json(['error' => 'Failed to download file'], 403);
        }
    }
    protected function forbiddenResponse()
    {
        return Response::json(['error' => 'Forbidden'], 403);
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

    private function getMedia($mediaId, Organization $organization)
    {
        //	$time= microtime(true);
        $metadata = json_decode($organization->metadata);

        if (empty($metadata) || empty($metadata->whatsapp->access_token)) {
            return $this->forbiddenResponse();
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
            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            return Response::json(['error' => 'Method Invalid'], 400);
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
