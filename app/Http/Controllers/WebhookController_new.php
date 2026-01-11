<?php

namespace App\Http\Controllers;

use App\Events\NewChatEvent;
use App\Helpers\WebhookHelper;
use App\Http\Controllers\Controller as BaseController;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatMedia;
use App\Models\ChatStatusLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use App\Resolvers\PaymentPlatformResolver;
use App\Services\AutoReplyService;
use App\Services\ChatService;
use App\Services\PhoneService;
use App\Services\SubscriptionService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Exception;
use Str;

class WebhookController extends BaseController
{
    protected $paymentPlatformResolver;

    public function __construct()
    {
        $this->paymentPlatformResolver = new PaymentPlatformResolver();

        // Configure broadcasting pusher keys (note: cache values by env in production)
        Config::set('broadcasting.connections.pusher', [
            'driver' => 'pusher',
            'key' => Setting::where('key', 'pusher_app_key')->value('value'),
            'secret' => Setting::where('key', 'pusher_app_secret')->value('value'),
            'app_id' => Setting::where('key', 'pusher_app_id')->value('value'),
            'options' => [
                'cluster' => Setting::where('key', 'pusher_app_cluster')->value('value'),
            ],
        ]);
    }

    /**
     * Endpoint used for classic webhook verification (for older callback flow)
     */
    public function whatsappWebhook(Request $request)
    {
        try {
            $verifyTokenSetting = Setting::where('key', 'whatsapp_callback_token')->first();
            $verifyToken = $verifyTokenSetting ? $verifyTokenSetting->value : null;

            $mode = $request->input('hub_mode');
            $token = $request->input('hub_verify_token');
            $challenge = $request->input('hub_challenge');

            if ($mode === 'subscribe' && $token === $verifyToken) {
                return Response::make($challenge, 200);
            }

            return Response::json(['error' => 'Forbidden'], 403);
        } catch (Exception $e) {
            Log::error('whatsappWebhook error: ' . $e->getMessage());
            return Response::json(['error' => 'Internal Server Error'], 500);
        }
    }

    public function handle(Request $request, $identifier = null)
    {
        $organization = $this->getOrganizationByIdentifier($identifier);

        if (!$organization) {
            return $this->forbiddenResponse();
        }

        return $this->handleMethod($request, $organization);
    }

    protected function getOrganizationByIdentifier($identifier)
    {
        return Organization::where('identifier', $identifier)->first();
    }

    protected function handleMethod(Request $request, Organization $organization)
    {
        if ($request->isMethod('get')) {
            return $this->handleGetRequest($request, $organization);
        } elseif ($request->isMethod('post')) {
            $metadata = json_decode($organization->metadata);

            if (empty($metadata)) {
                return $this->forbiddenResponse();
            }

            // If an app secret is configured, validate the signature
            if (!empty($metadata->whatsapp->app_secret)) {
                $appSecret = $metadata->whatsapp->app_secret;
                $headerSignature = $request->header('X-Hub-Signature-256');
                $payload = $request->getContent();
                $calculatedSignature = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);

                if (!$this->isValidSignature($calculatedSignature, $headerSignature)) {
                    Log::warning('Invalid signature for whatsapp webhook', ['org' => $organization->id]);
                    return $this->invalidSignatureResponse();
                }
            }

            return $this->handlePostRequest($request, $organization);
        }

        return Response::json(['error' => 'Method Not Allowed'], 405);
    }

    protected function forbiddenResponse()
    {
        return Response::json(['error' => 'Forbidden'], 403);
    }

    protected function isValidSignature($calculatedSignature, $headerSignature)
    {
        if (empty($headerSignature)) {
            return false;
        }

        return hash_equals($calculatedSignature, $headerSignature);
    }

    protected function invalidSignatureResponse()
    {
        return Response::json(['status' => 'error', 'message' => __('Invalid payload signature')], 400);
    }

    protected function handleGetRequest(Request $request, Organization $organization)
    {
        try {
            $verifyToken = Setting::where('key', 'whatsapp_callback_token')->first()->value ?? null;

            $mode = $request->input('hub_mode');
            $token = $request->input('hub_verify_token');
            $challenge = $request->input('hub_challenge');

            if ($mode === 'subscribe' && $token === $verifyToken) {
                return Response::make($challenge, 200);
            }

            return Response::json(['error' => 'Forbidden'], 403);
        } catch (Exception $e) {
            Log::error("Error processing webhook GET: " . $e->getMessage());
            return Response::json(['error' => 'Internal Server Error'], 500);
        }
    }

    /**
     * Main POST handler for WhatsApp Cloud API notifications.
     * This method is defensive: loops entries/changes, checks keys before use, and logs but continues.
     */
    protected function handlePostRequest(Request $request, Organization $organization)
    {
        $body = json_decode($request->getContent(), true);

        if (empty($body) || !isset($body['entry']) || !is_array($body['entry'])) {
            Log::warning('Webhook received with empty or invalid body', ['body' => $body]);
            return Response::json(['status' => 'ignored'], 200);
        }

        // Process every entry/change to be robust to batched webhooks
        foreach ($body['entry'] as $entry) {
            if (!isset($entry['changes']) || !is_array($entry['changes'])) {
                continue;
            }

            foreach ($entry['changes'] as $change) {
                try {
                    $field = $change['field'] ?? null;

                    if (!$field) {
                        Log::warning('Webhook change without field', ['change' => $change]);
                        continue;
                    }

                    // Centralized value array for this change
                    $value = $change['value'] ?? [];

                    if ($field === 'messages') {
                        $contacts = $value['contacts'] ?? [];
                        $messages = $value['messages'] ?? [];
                        $statuses = $value['statuses'] ?? [];

                        // handle statuses (delivery/read/etc)
                        if (!empty($statuses) && is_array($statuses)) {
                            $this->handleStatuses($statuses);

                            WebhookHelper::triggerWebhookEvent('message.status.update', [
                                'data' => $change,
                            ], $organization->id);

                            continue; // go next change
                        }

                        if (!empty($messages) && is_array($messages)) {
                            $isLimitReached = SubscriptionService::isSubscriptionLimitReachedForInboundMessages($organization->id);

                            if ($isLimitReached) {
                                Log::info('Inbound message limit reached for organization', ['org' => $organization->id]);
                                continue;
                            }

                            foreach ($messages as $message) {
                                try {
                                    $this->processInboundMessage($message, $contacts, $organization);
                                } catch (Exception $e) {
                                    Log::error('Failed to process single message: ' . $e->getMessage(), ['message' => $message]);
                                    // continue processing other messages
                                    continue;
                                }
                            }

                            WebhookHelper::triggerWebhookEvent('message.received', [
                                'data' => $change,
                            ], $organization->id);
                        }

                    } elseif ($field === 'message_template_status_update') {
                        $response = $value ?? null;
                        $template = Template::where('meta_id', $response['message_template_id'] ?? null)->first();

                        if ($template) {
                            $template->status = $response['event'] ?? $template->status;
                            $template->save();
                        }

                    } elseif ($field === 'account_review_update') {
                        $response = $value ?? null;
                        $this->updateOrganizationMetadata($organization->id, 'account_review_status', $response['decision'] ?? null);

                    } elseif ($field === 'phone_number_name_update') {
                        $response = $value ?? null;

                        if (($response['decision'] ?? null) === 'APPROVED') {
                            $this->updateOrganizationMetadata($organization->id, 'verified_name', $response['requested_verified_name'] ?? null);
                        }

                    } elseif ($field === 'phone_number_quality_update') {
                        $response = $value ?? null;
                        $this->updateOrganizationMetadata($organization->id, 'messaging_limit_tier', $response['current_limit'] ?? null);

                    } elseif ($field === 'business_capability_update') {
                        $response = $value ?? null;
                        $this->updateOrganizationMetadata($organization->id, 'max_daily_conversation_per_phone', $response['max_daily_conversation_per_phone'] ?? null);
                        $this->updateOrganizationMetadata($organization->id, 'max_phone_numbers_per_business', $response['max_phone_numbers_per_business'] ?? null);
                    } else {
                        Log::info('Unhandled webhook field', ['field' => $field]);
                    }
                } catch (Exception $e) {
                    Log::error('Error handling change: ' . $e->getMessage(), ['change' => $change]);
                    continue;
                }
            }
        }

        return Response::json(['status' => 'success'], 200);
    }

    protected function handleStatuses(array $statuses)
    {
        foreach ($statuses as $response) {
            try {
                $chatWamId = $response['id'] ?? null;
                $status = $response['status'] ?? null;

                if (!$chatWamId) continue;

                $chat = Chat::where('wam_id', $chatWamId)->first();

                if ($chat) {
                    $chat->status = $status;
                    $chat->save();

                    $chatStatusLog = new ChatStatusLog();
                    $chatStatusLog->chat_id = $chat->id;
                    $chatStatusLog->metadata = json_encode($response);
                    $chatStatusLog->save();
                }
            } catch (Exception $e) {
                Log::warning('Failed to process status item: ' . $e->getMessage(), ['status' => $response]);
            }
        }
    }

    protected function processInboundMessage(array $message, array $contacts, Organization $organization)
    {
        $phone = $message['from'] ?? null;
        if (!$phone) {
            throw new Exception('Missing from field');
        }

        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }

        // Format phone via PhoneService but guard against exceptions
        try {
            $phone = PhoneService::getE164Format($phone);
        } catch (Exception $e) {
            Log::warning('Phone formatting failed, proceeding with raw phone', ['phone' => $phone, 'error' => $e->getMessage()]);
        }

        // Find or create contact
        $contact = Contact::where('organization_id', $organization->id)
            ->where('phone', $phone)
            ->whereNull('deleted_at')
            ->first();

        $isNewContact = false;

        if (!$contact) {
            $contactData = $contacts[0]['profile'] ?? [];

            $contact = Contact::create([
                'first_name' => $contactData['name'] ?? null,
                'last_name' => null,
                'email' => null,
                'phone' => $phone,
                'organization_id' => $organization->id,
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $isNewContact = true;
        }

        // Ensure contact has a name if profile provided
        if ($contact && empty($contact->first_name)) {
            $contactData = $contacts[0]['profile'] ?? null;
            if (!empty($contactData['name'])) {
                $contact->update(['first_name' => $contactData['name']]);
            }
        }

        // Check or create chat
        $chat = Chat::where('wam_id', $message['id'] ?? null)
            ->where('organization_id', $organization->id)
            ->first();

        if (!$chat) {
            // Ticket assignment (might dispatch or modify DB) handled inside service
            (new ChatService($organization->id))->handleTicketAssignment($contact->id);

            $chat = new Chat();
            $chat->organization_id = $organization->id;
            $chat->wam_id = $message['id'] ?? null;
            $chat->contact_id = $contact->id;
            $chat->type = 'inbound';
            $chat->metadata = json_encode($message);
            $chat->status = 'delivered';
            $chat->save();

            // handle media if exists
            $this->handleMessageMedia($chat, $message, $organization);

            // Prepare event payload and chat log
            $chat = Chat::with('contact', 'media')->find($chat->id);

            $chatlogId = ChatLog::insertGetId([
                'contact_id' => $contact->id,
                'entity_type' => 'chat',
                'entity_id' => $chat->id,
                'created_at' => now(),
            ]);

            $chatLogArray = ChatLog::where('id', $chatlogId)->whereNull('deleted_at')->first();

            $chatArray = array([
                'type' => 'chat',
                'value' => $chatLogArray ? $chatLogArray->relatedEntities : null,
            ]);

            event(new NewChatEvent($chatArray, $organization->id));

            $isMessageLimitReached = SubscriptionService::isSubscriptionFeatureLimitReached($organization->id, 'message_limit');

            if (!$isMessageLimitReached) {
                $type = $message['type'] ?? null;

                if (in_array($type, ['text', 'button', 'audio', 'interactive'])) {
                    (new AutoReplyService)->checkAutoReply($chat, $isNewContact);
                }
            }
        }

        return true;
    }

    protected function handleMessageMedia(Chat $chat, array $message, Organization $organization)
    {
        $type = $message['type'] ?? null;

        if (!$type) return;

        $mediaTypes = ['image', 'video', 'audio', 'document', 'sticker'];

        if (!in_array($type, $mediaTypes)) return;

        $mediaData = $message[$type] ?? [];
        $mediaId = $mediaData['id'] ?? null;

        if (!$mediaId) {
            Log::warning('Media type reported without id', ['message' => $message]);
            return;
        }

        try {
            $media = $this->getMedia($mediaId, $organization);
            if (empty($media) || empty($media['url'])) {
                Log::warning('getMedia returned empty for id', ['id' => $mediaId]);
                return;
            }

            $downloaded = $this->downloadMedia($media, $organization);

            if (empty($downloaded['media_url'])) {
                Log::warning('downloadMedia failed or returned empty url', ['media' => $media]);
                return;
            }

            $chatMedia = new ChatMedia();
            $chatMedia->name = ($type === 'document') ? ($mediaData['filename'] ?? 'document') : ($mediaData['caption'] ?? 'N/A');
            $chatMedia->path = $downloaded['media_url'];
            $chatMedia->type = $media['mime_type'] ?? ($mediaData['mime_type'] ?? 'application/octet-stream');
            $chatMedia->size = $media['file_size'] ?? null;
            $chatMedia->location = $downloaded['location'] ?? null;
            $chatMedia->created_at = now();
            $chatMedia->save();

            // Update chat with media id
            $chat->update(['media_id' => $chatMedia->id]);

        } catch (Exception $e) {
            Log::error('Error processing message media: ' . $e->getMessage(), ['message' => $message]);
        }
    }

    /**
     * Download media binary and store according to configured storage
     * Returns ['media_url' => ..., 'location' => ...]
     */
    private function downloadMedia(array $mediaInfo, Organization $organization)
    {
        $metadata = json_decode($organization->metadata);

        if (empty($metadata) || empty($metadata->whatsapp->access_token)) {
            throw new Exception('Missing whatsapp access token in organization metadata');
        }

        try {
            $client = new Client();

            $requestOptions = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $metadata->whatsapp->access_token,
                ],
                'stream' => true,
                'http_errors' => true,
            ];

            $response = $client->request('GET', $mediaInfo['url'], $requestOptions);

            $fileContent = $response->getBody()->getContents();
            $mimeType = $mediaInfo['mime_type'] ?? 'application/octet-stream';
            $fileName = $this->generateFilename($fileContent, $mimeType);

            $storage = Setting::where('key', 'storage_system')->first()->value ?? 'local';

            if ($storage === 'local') {
                $location = 'local';
                $path = 'public/' . $fileName;
                Storage::disk('local')->put($path, $fileContent);
                $mediaUrl = Storage::url($path);

            } else { // assume s3
                $location = 'amazon';
                $filePath = 'uploads/media/received/' . $organization->id . '/' . Str::random(40) . time();
                Storage::disk('s3')->put($filePath, $fileContent, [
                    'ContentType' => $mimeType,
                ]);
                $mediaUrl = Storage::disk('s3')->url($filePath);
            }

            return [
                'media_url' => $mediaUrl,
                'location' => $location,
            ];
        } catch (RequestException $e) {
            Log::error('Failed to download media: ' . $e->getMessage());
            throw new Exception('Failed to download media');
        }
    }

    private function generateFilename($fileContent, $mimeType)
    {
        // Ensure $fileContent is string
        if (is_object($fileContent) && method_exists($fileContent, 'getContents')) {
            $fileContent = $fileContent->getContents();
        }

        $hash = sha1($fileContent);

        $extension = 'bin';
        if (!empty($mimeType) && is_string($mimeType) && strpos($mimeType, '/') !== false) {
            $parts = explode('/', $mimeType);
            $extension = end($parts) ?: 'bin';
            // sanitize extension
            $extension = preg_replace('/[^a-z0-9]/i', '', $extension);
        }

        return "{$hash}_" . time() . ".{$extension}";
    }

    private function getMedia($mediaId, Organization $organization)
    {
        $metadata = json_decode($organization->metadata);

        if (empty($metadata) || empty($metadata->whatsapp->access_token)) {
            throw new Exception('Missing whatsapp access token in organization metadata');
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
        } catch (RequestException $e) {
            Log::error('getMedia failed: ' . $e->getMessage());
            throw new Exception('Failed to fetch media metadata');
        }
    }

    protected function updateOrganizationMetadata($organizationId, $key, $value)
    {
        $organizationConfig = Organization::where('id', $organizationId)->first();
        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

        if (!isset($metadataArray['whatsapp'])) {
            $metadataArray['whatsapp'] = [];
        }

        $metadataArray['whatsapp'][$key] = $value;

        $organizationConfig->metadata = json_encode($metadataArray);
        $organizationConfig->save();
    }

    public function processWebhook(Request $request, $processor)
    {
        $paymentPlatform = $this->paymentPlatformResolver->resolveService($processor);
        session()->put('paymentPlatform', $processor);

        return $paymentPlatform->handleWebhook($request);
    }
}
