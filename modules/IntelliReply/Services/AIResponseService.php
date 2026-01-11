<?php

namespace Modules\IntelliReply\Services;

use App\Helpers\CustomHelper;
use App\Models\Chat;
use App\Models\ChatMedia;
use App\Models\ChatTicketLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Services\WhatsappService;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use Illuminate\Support\Facades\Log;
use Modules\IntelliReply\Models\Document;
use OpenAI;

class AIResponseService
{
    private const MODULE_NAME = 'AI Assistant';
    private const CONVERSATION_HISTORY_LIMIT = 10;
    private const INBOUND_MESSAGE_CHECK_HOURS = 24;
    
    private ?WhatsappService $whatsappService = null;

    public function handleAIResponse($chat, $receivedMessage): bool
    {
        try {
            $organizationId = $chat->organization_id;
            $contactId = $chat->contact_id;
            $contact = Contact::find($chat->contact_id);
            
            // Process the response
            $response = $this->processResponse(true, $organizationId, $contactId);
            if (!$response) {
                return false;
            }

            $aiConfig = $this->getAIConfiguration($organizationId);
            $lastMessage = $this->extractLastMessage($organizationId, $contactId);
            
            // Send the response
            return $this->sendResponse($contact, $lastMessage['type'], $response, $aiConfig);

        } catch (\Throwable $e) {
            Log::error('AI Response Error: ' . $e->getMessage());
            return false;
        }
    }

    public function processResponse(bool $autoResponseCheck, int $organizationId, int $contactId): ?array
    {
        // Validate module and configuration
        if (!$this->isAIModuleEnabled($organizationId)) {
            return null;
        }

        $aiConfig = $this->getAIConfiguration($organizationId);
        if (!$aiConfig['is_active']) {
            return null;
        }

        // Get and process last message
        $lastMessage = $this->extractLastMessage($organizationId, $contactId);

        $this->updateAIAssistanceState($contactId, $lastMessage['message'], $aiConfig);

        if($autoResponseCheck){
            $contact = Contact::find($contactId);

            // Check if automatic response should be enabled
            if ($this->shouldEnableAutomaticResponse($contactId, $aiConfig)) {
                $contact->ai_assistance_enabled = true;
                $contact->save();
            }

            // Verify AI assistance is enabled
            if (!$contact->ai_assistance_enabled) {
                return null;
            }
        }

        // Generate AI response
        $closestDocument = $this->findClosestDocumentByQuery($organizationId, $lastMessage['message']);
        $context = $this->buildConversationContext($organizationId, $contactId, $closestDocument);
        
        return $this->chat($organizationId, $lastMessage['type'], $context);
    }

    private function shouldEnableAutomaticResponse(int $contactId, array $aiConfig): bool
    {
        $contact = Contact::find($contactId);

        if (!$aiConfig['enable_automatic_responses'] || $contact->ai_assistance_enabled) {
            return false;
        }

        return $aiConfig['chat_ticketing'] 
            ? $this->checkTicketingWorkflow($contactId)
            : $this->checkMessageHistory($contactId);
    }

    private function checkTicketingWorkflow(int $contactId): bool
    {
        $lastTicket = ChatTicketLog::where('contact_id', $contactId)
            ->where(function($query) {
                $query->where('description', 'Conversation was opened')
                    ->orWhere('description', 'Conversation was moved from closed to open');
            })
            ->latest()
            ->first();

        if (!$lastTicket) {
            return true;
        }

        return Chat::where('contact_id', $contactId)
            ->where('created_at', '>', $lastTicket->created_at)
            ->where('type', 'outbound')
            ->count() === 0;
    }

    private function checkMessageHistory(int $contactId): bool
    {
        return Chat::where('contact_id', $contactId)
            ->where('type', 'inbound')
            ->where('created_at', '>', now()->subHours(self::INBOUND_MESSAGE_CHECK_HOURS))
            ->count() <= 1;
    }

    private function sendResponse(Contact $contact, string $messageType, array $response, array $aiConfig): bool
    {
        $whatsappService = $this->getWhatsappService($contact->organization_id);

        if ($messageType === 'text') {
            return $this->sendTextResponse($whatsappService, $contact->uuid, $response['text']);
        }

        if ($messageType === 'audio' && $aiConfig['allow_audio_response']) {
            return $this->sendAudioResponse($whatsappService, $contact, $response['text']);
        }

        return false;
    }

    private function sendTextResponse(WhatsappService $whatsappService, string $uuid, string $text): bool
    {
        $result = $whatsappService->sendMessage($uuid, $text);
        return $this->isSuccessfulResponse($result);
    }

    private function sendAudioResponse(WhatsappService $whatsappService, Contact $contact, $text): bool
    {
        $organizationId = $contact->organization_id;
        $ttsResponse = $this->convertTextToSpeech($organizationId, $text);
        $fileName = 'speech_' . uniqid() . '.mp3';

        if($ttsResponse['success']){
            $settings = Setting::whereIn('key', [
                'storage_system',
            ])->pluck('value', 'key');
            $storage = $settings->get('storage_system', 'local');

            if ($storage === 'local') {
                $filePath = 'public/audios/' . $fileName;
                \Storage::disk('local')->put($filePath, $ttsResponse['body']);
                $mediaUrl = rtrim(config('app.url'), '/') . '/media/public/audios/' . $fileName;
            } else {
                $filePath = 'uploads/media/sent/' . $organizationId . '/' . $fileName;
                \Storage::disk('s3')->put($filePath, $ttsResponse['body']);
                $mediaUrl = \Storage::disk('s3')->url($filePath);
            }

            $filePath = $storage === 'aws' ? $mediaUrl : $filePath;
            $location = $storage === 'aws' ? 'amazon' : 'local';
            
            $result = $whatsappService->sendMedia($contact->uuid, 'audio', $fileName, $filePath, $mediaUrl, $location, NULL, $text);

            return $this->isSuccessfulResponse($result);
        }
    }

    private function isSuccessfulResponse($result): bool
    {
        return $result === true || (is_object($result) && isset($result->success) && $result->success === true);
    }

    private function buildConversationContext(int $organizationId, int $contactId, ?array $closestDocument): array
    {
        $context = $this->buildSystemMessage($closestDocument);
        $conversationHistory = $this->getConversationHistory($organizationId, $contactId);
        return array_merge($context, $conversationHistory);
    }

    private function buildSystemMessage(?array $closestDocument): array
    {
        if (!$closestDocument || !$closestDocument['success']) {
            return [];
        }

        return [[
            'role' => 'system',
            'content' => $this->getSystemPrompt($closestDocument['document'])
        ]];
    }

    private function getSystemPrompt(string $documentation): string
    {
        return <<<EOT
You are a customer support AI chatbot. Your primary role is to assist users based on the provided documentation. 
- If the question is covered in the documentation, provide a clear and helpful response.
- If the information is not found in the documentation, respond in the same language as the user's last message with: 'Sorry, I don't have information about this. Could you specify what you'd like more information about?'
- If the message is a common greeting or polite phrase (e.g., 'hello', 'hi', 'thank you', 'bye'), respond appropriately with good etiquette.

Here is the documentation: $documentation
EOT;
    }

    private function getConversationHistory(int $organizationId, int $contactId): array
    {
        $messages = Chat::where('contact_id', $contactId)
            ->orderBy('created_at', 'desc')
            ->take(self::CONVERSATION_HISTORY_LIMIT)
            ->get();

        $conversationHistory = $messages->map(function ($message) use ($organizationId) {
            return $this->formatMessage($message, $organizationId);
        })->filter()->values()->toArray();

        return array_reverse($conversationHistory);
    }

    private function formatMessage(Chat $message, int $organizationId): ?array
    {
        $metadata = json_decode($message->metadata, true);
        $aiConfig = $this->getAIConfiguration($organizationId);

        if (!isset($metadata['type'])) {
            return null;
        }

        $role = ($message->type === 'outbound') ? 'assistant' : 'user';

        if ($metadata['type'] === 'text') {
            return $this->formatTextMessage($role, $metadata);
        }

        if ($metadata['type'] === 'audio' && $aiConfig['allow_audio_response']) {
            return $this->formatAudioMessage($role, $message);
        }

        return null;
    }

    private function formatTextMessage(string $role, array $metadata): ?array
    {
        return [
            "role" => $role,
            "content" => $metadata['text']['body'] ?? null
        ];
    }

    private function formatAudioMessage(string $role, $message): ?array
    {
        // Decode metadata safely
        $metadata = json_decode($message->metadata, true) ?? [];

        // If no transcript exists, we can't format this message
        if (empty($metadata['transcript'])) {
            return null;
        }

        return [
            "role"    => $role,
            "content" => $metadata['transcript'],
        ];
    }

    private function formatAssistantAudioMessage(string $role, ChatMedia $audio): ?array
    {
        if (!isset($audio->name) || !str_starts_with($audio->name, 'audio_')) {
            return null;
        }

        return [
            'role' => $role,
            'audio' => [
                'id' => $audio->name,
            ],
        ];
    }

    private function getAudioFile(ChatMedia $audio): ?array
    {
        if ($audio->location === 'local') {
            return $this->getLocalAudioFile($audio);
        }

        if ($audio->location === 'amazon') {
            return $this->getAmazonAudioFile($audio);
        }

        return null;
    }

    private function getLocalAudioFile(ChatMedia $audio): ?array
    {
        $filePath = storage_path("app/{$audio->path}");
        if (!file_exists($filePath)) {
            return null;
        }

        return $this->convertToMp3($filePath, 'local');
    }

    private function getAmazonAudioFile(ChatMedia $audio): ?array
    {
        $parsedUrl = parse_url($audio->path);
        $filePath = ltrim($parsedUrl['path'], '/');

        if (!\Storage::disk('s3')->exists($filePath)) {
            return null;
        }

        return $this->convertToMp3($audio->path, 'amazon');
    }

    private function updateAIAssistanceState(int $contactId, string $message, array $aiConfig): void
    {
        $contact = Contact::find($contactId);
        $message = strtolower($message);

        if ($this->containsKeyword($message, $aiConfig['start_keywords'])) {
            $contact->ai_assistance_enabled = true;
            $contact->save();
            return;
        }

        if ($this->containsKeyword($message, $aiConfig['stop_keywords'])) {
            $contact->ai_assistance_enabled = false;
            $contact->save();
        }
    }

    private function containsKeyword(string $message, string $keywords): bool
    {
        if (empty($keywords)) {
            return false;
        }

        $keywordsArray = array_map('trim', explode(',', strtolower($keywords)));
        return collect($keywordsArray)->contains(fn($keyword) => str_contains($message, $keyword));
    }

    private function isAIModuleEnabled(int $organizationId): bool
    {
        return CustomHelper::isModuleEnabled(self::MODULE_NAME, $organizationId);
    }

    private function getAIConfiguration(int $organizationId): array
    {
        $organization = Organization::find($organizationId);
        $metadata = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $aiMetadata = $metadata['ai'] ?? [];
        $settings = Setting::whereIn('key', [
            'default_open_ai_key',
            'default_open_ai_model',
            'enable_model_selection',
            'enable_api_key_input'
        ])->pluck('value', 'key');

        $enableApiKeyInput = $settings->get('enable_api_key_input', 1);
        $defaultOpenAiKey = $settings->get('default_open_ai_key', $aiMetadata['api_key'] ?? '');
        $defaultOpenAiTextModel = $settings->get('default_open_ai_model', $aiMetadata['model'] ?? '');
        $enableModelSelection = $settings->get('enable_model_selection', 1);

        return [
            'is_active' => $aiMetadata['active'] ?? false,
            'enable_automatic_responses' => $aiMetadata['enable_automatic_responses'] ?? false,
            'start_keywords' => $aiMetadata['start_keywords'] ?? '',
            'stop_keywords' => $aiMetadata['stop_keywords'] ?? '',
            'allow_audio_response' => $aiMetadata['allow_audio_response'] ?? false,
            'model' => $enableModelSelection == 1 ? $aiMetadata['model'] ?? '' : $defaultOpenAiTextModel,
            'voice' => $aiMetadata['voice'] ?? '',
            'api_key' => $enableApiKeyInput == 1 ? $aiMetadata['api_key'] ?? '' : $defaultOpenAiKey,
            'chat_ticketing' => $metadata['tickets']['active'] ?? false,
        ];
    }

    private function getWhatsappService(int $organizationId): WhatsappService
    {
        if (!$this->whatsappService) {
            $this->whatsappService = $this->initializeWhatsappService($organizationId);
        }
        return $this->whatsappService;
    }

    private function initializeWhatsappService(int $organizationId): WhatsappService
    {
        $organization = Organization::find($organizationId);
        $config = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $whatsappConfig = $config['whatsapp'] ?? [];

        return new WhatsappService(
            $whatsappConfig['access_token'] ?? null,
            'v18.0',
            $whatsappConfig['app_id'] ?? null,
            $whatsappConfig['phone_number_id'] ?? null,
            $whatsappConfig['waba_id'] ?? null,
            $organizationId
        );
    }

    private function chat(int $organizationId, string $type, array $context): ?array
    {
        try {
            $organizationConfig = $this->getAIConfiguration($organizationId);
            $response = $this->makeOpenAIRequest($organizationConfig, $context);
            return $this->parseOpenAIResponse($response->json());
        } catch (\Throwable $e) {
            Log::error('OpenAI Chat Error: ' . $e->getMessage());
            return null;
        }
    }

    private function makeOpenAIRequest(array $config, array $context)
    {
        $settings = Setting::whereIn('key', [
            'default_open_ai_key',
            'default_open_ai_model',
          	'enable_model_selection',
            'enable_api_key_input',
        ])->pluck('value', 'key');

        $enableApiKeyInput = $settings->get('enable_api_key_input', 1);
        $defaultOpenAiKey = $settings->get('default_open_ai_key', $config['api_key'] ?? '');
        $defaultOpenAiModel = $settings->get('default_open_ai_model', $config['model'] ?? '');
        $enableModelSelection = $settings->get('enable_model_selection', 1);
        $api_key = $enableApiKeyInput == 1 ? $config['api_key'] : $defaultOpenAiKey;
        $model = $enableModelSelection == 1 ? $config['model'] : $defaultOpenAiModel;

        $payload = [
            'model' => $model,
            'messages' => $context
        ];
        /*if ($config['model'] === 'gpt-4o-audio-preview') {
            $payload['modalities'] = ["text", "audio"];
            $payload['audio'] = [
                "voice" => $config['voice'],
                "format" => "mp3"
            ];
        }*/

        return \Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ])->post('https://api.openai.com/v1/chat/completions', $payload);
    }

    private function parseOpenAIResponse(array $responseArray): ?array
    {
        if (!isset($responseArray['choices'][0]['message'])) {
            return null;
        }

        $message = $responseArray['choices'][0]['message'];
        
        if (isset($message['audio'])) {
            return [
                'type' => 'audio',
                'text' => $message['audio']['transcript'],
                'audio' => [
                    'id' => $message['audio']['id'],
                    'data' => $message['audio']['data'],
                    'transcript' => $message['audio']['transcript']
                ]
            ];
        }

        return [
            'type' => 'text',
            'text' => $message['content']
        ];
    }

    private function convertToMp3(string $filePath, $fileLocation): ?array
    {
        try {
            if($fileLocation == 'amazon'){
              $headers = get_headers($filePath, 1);
              $contentType = $headers['Content-Type'] ?? null;
              $mimeToExt = [
                'audio/ogg; codecs=opus' => 'opus',
                'audio/opus'      => 'opus',
                'audio/ogg'       => 'opus',
                'audio/mpeg'      => 'mp3',
                'audio/wav'       => 'wav',
                'audio/x-wav'     => 'wav',
                'audio/L16'       => 'pcm16',
                'audio/webm'      => 'webm',
                'audio/flac'      => 'flac',
                'audio/x-flac'    => 'flac',
                'audio/amr'   => 'amr',
                'audio/mp4'   => 'm4a',
                'audio/aac'   => 'aac',
              ];
              $extension = $mimeToExt[$contentType] ?? 'unknown';
            } elseif($fileLocation == 'local'){
            	$extension = pathinfo($filePath, PATHINFO_EXTENSION);
            }
            
            if (in_array(strtolower($extension), ['wav', 'mp3', 'flac', 'flac', 'opus', 'pcm16'])) {
                return $this->encodeAudioFile($filePath, $extension);
            }

            return $this->convertAndEncodeAudio($filePath);
        } catch (\Throwable $e) {
            Log::error('Audio Conversion Error: ' . $e->getMessage());
            return null;
        }
    }

    private function encodeAudioFile(string $filePath, $extension): array
    {
        $base64Data = base64_encode(file_get_contents($filePath));

        return [
            'data' => $base64Data,
            'format' => $extension,
        ];
    }

    private function convertAndEncodeAudio(string $filePath): array
    {
      	// Download file if remote
        if (str_starts_with($filePath, 'http')) {
            $tempInput = tempnam(sys_get_temp_dir(), 'input_audio');
            file_put_contents($tempInput, file_get_contents($filePath));
        } else {
            $tempInput = $filePath;
        }
      
        $ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => config('ffmpeg.ffmpeg.binaries'),
            'ffprobe.binaries' => config('ffmpeg.ffprobe.binaries'),
            'timeout' => config('ffmpeg.timeout'),
            'threads' => config('ffmpeg.threads'),
        ]);

        $audio = $ffmpeg->open($tempInput);
    	$format = new Mp3();
    	$tempOutput = tempnam(sys_get_temp_dir(), 'audio') . '.mp3';
        
        $audio->save($format, $tempOutput);
    	$base64Data = base64_encode(file_get_contents($tempOutput));
        
      	// Clean up
        if (isset($tempInput) && $tempInput !== $filePath) {
            unlink($tempInput);
        }
      	unlink($tempOutput);

        return [
            'data' => $base64Data,
            'format' => 'mp3',
        ];
    }

    protected function extractLastMessage(int $organizationId, int $contactId): array
    {
        $chat = Chat::where('contact_id', $contactId)->latest()->first(); 

        if (!$chat) {
            return ['type' => 'text', 'message' => null];
        }

        $metadata = json_decode($chat->metadata ?? '{}', true);
        $type = $metadata['type'] ?? 'text';

        switch ($type) {
            case 'text':
                return [
                    'type' => 'text',
                    'message' => $metadata['text']['body'] ?? null
                ];

            case 'button':
                return [
                    'type' => 'text',
                    'message' => $metadata['button']['payload'] ?? null
                ];

            case 'audio':
                $mediaId = $chat->media_id;
                if (!$mediaId) {
                    return ['type' => 'text', 'message' => null];
                }
        
                $audio = ChatMedia::find($mediaId);
                if (!$audio) {
                    return ['type' => 'text', 'message' => null];
                }

                $transcript = $metadata['transcript'] ?? null;
            
                if (!$transcript) {
                    //$filePath = $audio->location === 'local' ? storage_path("app/{$audio->path}") : $audio->path;
                    $filePath = $this->getMediaLocalPathOrDownload($audio->path);

                    // Generate transcript
                    $transcription = $this->transcribeAudioToText($organizationId, $filePath);

                    // Save transcript back to metadata
                    if($transcription['success']){
                        $transcript = $transcription['text'];
                        $metadata['transcript'] = $transcript;
                        $chat->metadata = json_encode($metadata);
                        $chat->save();
                    }
                }
    
                return [
                    'type' => 'audio',
                    'message' => $transcript
                ];
        }

        return ['type' => 'text', 'message' => null];
    }

    private function transcribeAudioToText(int $organizationId, string $audioPath): array
    {
        try {
            $config = $this->getAIConfiguration($organizationId);

            $response = \Http::withHeaders([
                'Authorization' => 'Bearer ' . $config['api_key']
            ])->attach(
                'file', 
                file_get_contents($audioPath), 
                'audio.mp3'
            )->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => 'whisper-1',
                'language' => 'en'
            ]);

            $result = $response->json();

            return [
                'success' => isset($result['text']),
                'text' => $result['text'] ?? null
            ];
        } catch (\Throwable $e) {
            Log::error('Audio Transcription Error: ' . $e->getMessage());
            return [
                'success' => false,
                'text' => null
            ];
        }
    }

    private function convertTextToSpeech(int $organizationId, string $text): array
    {
        try {
            $config = $this->getAIConfiguration($organizationId);

            $response = \Http::withHeaders([
                'Authorization' => 'Bearer ' . $config['api_key'],
                'Content-Type' => 'application/json',
            ])->withBody(json_encode([
                'model' => 'gpt-4o-mini-tts',
                'input' => $text,
                'voice' => $config['voice'],
            ]), 'application/json')->send('POST', 'https://api.openai.com/v1/audio/speech');

            if ($response->successful()) {
                return [
                    'success' => true,
                    'body' => $response->body(),
                    'error' => null,
                ];
            }

            \Log::error('OpenAI TTS API Error: ' . $response->body());
            return [
                'success' => false,
                'body' => null,
                'error' => $response->body(),
            ];
        } catch (\Throwable $e) {
            \Log::error('Audio Transcription Error: ' . $e->getMessage());
            return [
                'success' => false,
                'body' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function findClosestDocumentByQuery(int $organizationId, ?string $query): array
    {
        if (!$query) {
            return ['success' => false];
        }

        try {
            $config = $this->getAIConfiguration($organizationId);
            $client = OpenAI::client($config['api_key']);
            
            $embedding = $this->generateEmbedding($client, $query);
            return $this->findClosestDocument($organizationId, $embedding);
        } catch (\Throwable $e) {
            Log::error('Document Search Error: ' . $e->getMessage());
            return ['success' => false];
        }
    }

    private function generateEmbedding($client, string $query): array
    {
        $response = $client->embeddings()->create([
            'input' => $query,
            'model' => 'text-embedding-ada-002'
        ]);

        return $response->embeddings[0]->embedding;
    }

    private function findClosestDocument(int $organizationId, array $queryEmbedding): array
    {
        $documents = Document::where('organization_id', $organizationId)->get();
        $closestDocument = null;
        $closestDistance = PHP_FLOAT_MAX;

        foreach ($documents as $document) {
            $documentEmbeddings = json_decode($document->embeddings);
            foreach ($documentEmbeddings as $documentEmbedding) {
                $distance = $this->calculateCosineSimilarity($queryEmbedding, $documentEmbedding);
                if ($distance < $closestDistance) {
                    $closestDistance = $distance;
                    $closestDocument = $document;
                }
            }
        }

        return $closestDocument ? [
            'success' => true,
            'document' => $closestDocument->content
        ] : [
            'success' => false
        ];
    }

    private function calculateCosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;

        foreach ($vec1 as $i => $val1) {
            $val2 = $vec2[$i];
            $dotProduct += $val1 * $val2;
            $norm1 += $val1 * $val1;
            $norm2 += $val2 * $val2;
        }

        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);

        return 1 - ($dotProduct / ($norm1 * $norm2));
    }

    private function getMediaLocalPathOrDownload(string $url): string
    {
        $relativePath = ltrim(parse_url($url, PHP_URL_PATH), '/'); // media/public/filename.ogg
        $localPath = public_path($relativePath);

        if (file_exists($localPath)) {
            return $localPath; // Local file exists
        }

        // Fallback: download to temp file
        $tempPath = storage_path('app/temp_' . basename($relativePath));
        file_put_contents($tempPath, file_get_contents($url));
        return $tempPath;
    }
}