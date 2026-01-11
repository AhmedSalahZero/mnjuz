<?php

namespace App\Services;

use App\Helpers\DateTimeHelper;
use App\Helpers\WebhookHelper;
use App\Http\Resources\AutoReplyResource;
use App\Models\AutoReply;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Services\MediaService;
use App\Services\WhatsappService;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Validator;

class AutoReplyService
{
    public function getRows(object $request)
    {
        $organizationId = session()->get('current_organization');
        $model = new AutoReply;
        $searchTerm = $request->query('search');

        return AutoReplyResource::collection($model->listAll($organizationId, $searchTerm));
    }

    public function store(object $request, $uuid = null)
    {
        $model = $uuid == null ? new AutoReply : AutoReply::where('uuid', $uuid)->first();
        $model['name'] = $request->name;
        $model['trigger'] = $request->trigger;
        $model['match_criteria'] = $request->match_criteria;

        $metadata['type'] = $request->response_type;
        if($request->response_type === 'image' || $request->response_type === 'audio'){
            if($request->hasFile('response')){
                $storage = Setting::where('key', 'storage_system')->first()->value;
                $fileName = $request->file('response')->getClientOriginalName();
                $fileContent = $request->file('response');

                if($storage === 'local'){
                    $file = Storage::disk('local')->put('public', $fileContent);
                    $mediaFilePath = $file;
                    $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
                } else if($storage === 'aws') {
                    $filePath = 'uploads/media/received'  . session()->get('current_organization') . '/' . $fileName;
                    $file = Storage::disk('s3')->put($filePath, $fileContent, 'public');
                    $mediaFilePath = Storage::disk('s3')->url($filePath);
                    $mediaUrl = $mediaFilePath;
                }

                $uploadedMedia = MediaService::upload($request->file('response'));

                $metadata['data']['file']['name'] = $fileName;
                $metadata['data']['file']['location'] = $mediaFilePath;
                $metadata['data']['file']['url'] = $mediaUrl;
            } else {
                $media = json_decode($model->metadata)->data;
                $metadata['data']['file']['name'] = $media->file->name;
                $metadata['data']['file']['location'] = $media->file->location;
                $metadata['data']['file']['url'] = $media->file->url;
            }
        } else if($request->response_type === 'text') {
            $metadata['data']['text'] = $request->response;
        } else {
            $metadata['data']['template'] = $request->response;
        }

        $model['metadata'] = json_encode($metadata);
        $model['updated_at'] =  DateTimeHelper::convertToOrganizationTimezone(now(),$model->organization_id);

        if($uuid === null){
            $model['organization_id'] = session()->get('current_organization');
            $model['created_by'] = auth()->user()->id;
            $model['created_at'] =  DateTimeHelper::convertToOrganizationTimezone(now(),$model['organization_id']);
        }

        $model->save();

        // Prepare a clean contact object for webhook
        $cleanModel = $model->makeHidden(['id', 'organization_id', 'created_by']);

        // Trigger webhook
        WebhookHelper::triggerWebhookEvent($uuid === null ? 'autoreply.created' : 'autoreply.updated', $cleanModel);
    }

    public function destroy($uuid)
    {
        AutoReply::where('uuid', $uuid)->update([
            'deleted_by' => auth()->user()->id,
            'deleted_at' => now()
        ]);

        // Trigger webhook
        WebhookHelper::triggerWebhookEvent('autoreply.deleted', [
            'list' => [
                'uuid' => $uuid,
                'deleted_at' => now()->toISOString()
            ],
        ]);
    }

    public function checkAutoReply(Chat $chat, $isNewContact)
    {
        $organizationId = $chat->organization_id;

        $this->replySequence($organizationId, $chat, $isNewContact);
    }

    private function replySequence($organizationId, $chat, $isNewContact)
    {
	
		logger('inside replay sequence');
        $organizationConfig = Organization::where('id', $organizationId)->first();
        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
        $activeFlow = false;
        $modulePath = base_path('modules/FlowBuilder');
        
        if (File::exists($modulePath)) {
            if (class_exists(\Modules\FlowBuilder\Services\FlowExecutionService::class)) {
                $query = new \Modules\FlowBuilder\Services\FlowExecutionService($organizationId);
                $activeFlow = $query->hasActiveFlow($chat);
            }
        }

        // Override response sequence if there is an active flow
        if ($activeFlow) {
			logger('inside $activeFlow if');
            $response_sequence = ['Automated Flows'];
        } else {
			logger('inside $activeFlow else');
            // Use the response sequence from metadata or fallback to default
            $response_sequence = $metadataArray['automation']['response_sequence'] ?? ['Basic Replies', 'Automated Flows', 'AI Reply Assistant'];
        }

        // Define mapping of sequence items to functions
        $sequenceFunctions = [
            'Basic Replies' => function() use ($chat) {
                return $this->handleBasicReplies($chat);
            },
            'Automated Flows' => function() use ($organizationId, $chat, $isNewContact) {
                return $this->handleAutomatedFlows($organizationId, $chat, $isNewContact);
            },
            'AI Reply Assistant' => function() use ($chat) {
                return $this->handleAIReplyAssistant($chat);
            },
        ];

        // Initialize a variable to hold the response (or handle chaining, etc.)
        $response = null;

        // Iterate through the sequence, applying each function in order
        foreach ($response_sequence as $sequenceItem) {
            if (isset($sequenceFunctions[$sequenceItem])) {
				logger('inside if'.$sequenceItem);
                $response = $sequenceFunctions[$sequenceItem]();

                // \Log::info($sequenceItem);
                // \Log::info($response);

                if ($response) {
                    // If a response is found, exit the loop
                    break;
                }
            }
        }

        return $response;
    }

    private function handleBasicReplies($chat)
    {
        $organizationId = $chat->organization_id;
        $text = '';
        $metadata = json_decode($chat->metadata, true);

        if($metadata['type'] == 'text'){
            $text = $metadata['text']['body'];
        } else if(json_decode($chat->metadata)->type == 'button'){
            $text = $metadata['button']['payload'];
        } else if(json_decode($chat->metadata)->type == 'interactive'){
            if($metadata['interactive']['type'] == 'button_reply'){
                $text = $metadata['interactive']['button_reply']['title'];
            } else if($metadata['interactive']['type'] == 'list_reply'){
                $text = $metadata['interactive']['list_reply']['title'];
            }
        }
        
        $receivedMessage = " " . $text;

        //Check basic reply flow
        $autoReplies = AutoReply::where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->get();

        foreach ($autoReplies as $autoReply) {
            $triggerValues = $this->getTriggerValues($autoReply->trigger);

            foreach ($triggerValues as $trigger) {
                if ($this->checkMatch($receivedMessage, $trigger, $autoReply->match_criteria)) {
                    $this->sendReply($chat, $autoReply);
                    return true;
                }
            }
        }

        return false; // No reply was sent
    }

    private function handleAIReplyAssistant($chat)
    {
        $text = '';
        $metadata = json_decode($chat->metadata, true);

        if($metadata['type'] == 'text'){
            $text = $metadata['text']['body'];
        } else if(json_decode($chat->metadata)->type == 'button'){
            $text = $metadata['button']['payload'];
        } else if(json_decode($chat->metadata)->type == 'interactive'){
            if($metadata['interactive']['type'] == 'button_reply'){
                $text = $metadata['interactive']['button_reply']['title'];
            } else if($metadata['interactive']['type'] == 'list_reply'){
                $text = $metadata['interactive']['list_reply']['title'];
            }
        }
        
        $receivedMessage = " " . $text;

        if (file_exists(base_path('modules/IntelliReply/Services/AIResponseService.php'))) {
            $query = new \Modules\IntelliReply\Services\AIResponseService();
            if ($query->handleAIResponse($chat, $receivedMessage)) {
                return true;
            }
        }

        return false; // No reply was sent
    }

    private function handleAutomatedFlows($organizationId, $chat, $isNewContact)
    {
		logger('--inside automatted flow');
        $text = '';
        $metadata = json_decode($chat->metadata, true);

        if($metadata['type'] == 'text'){
			logger('--from automate text');
            $text = $metadata['text']['body'];
        } else if(json_decode($chat->metadata)->type == 'button'){
			logger('--from automate button');
            $text = $metadata['button']['payload'];
        } else if(json_decode($chat->metadata)->type == 'interactive'){
			logger('--from interactive button reply');
            if($metadata['interactive']['type'] == 'button_reply'){
                $text = $metadata['interactive']['button_reply']['title'];
            } else if($metadata['interactive']['type'] == 'list_reply'){
				logger('--from interactive button list replay');
                $text = $metadata['interactive']['list_reply']['title'];
            }
        }

        $receivedMessage = " " . $text;

        if (file_exists(base_path('modules/FlowBuilder/Services/FlowExecutionService.php'))) {
				logger('--start execute flow');
            $query = new \Modules\FlowBuilder\Services\FlowExecutionService($organizationId);
            return $query->executeFlow($chat, $isNewContact, $receivedMessage);
        }
    }

    private function getTriggerValues($trigger)
    {
		logger('get trigger values');
        return is_string($trigger) && strpos($trigger, ',') !== false
            ? explode(',', $trigger)
            : (array) $trigger;
    }

    private function checkMatch($receivedMessage, $trigger, $criteria)
    {
				logger('check matching');
        $normalizedTrigger = trim($trigger);

        if ($criteria === 'exact match') {
            // Check if the text contains Arabic characters
            $hasArabic = preg_match('/[\p{Arabic}]/u', $receivedMessage . $normalizedTrigger);
            
            if ($hasArabic) {
                // For Arabic text, use exact case-sensitive matching
                return $receivedMessage === " " . $normalizedTrigger;
            } else {
                // For English text, use case-insensitive matching
                return strtolower($receivedMessage) === " " . strtolower($normalizedTrigger);
            }
        } else if ($criteria === 'contains') {
            $triggerWords = explode(' ', $normalizedTrigger);
            
            // Check if the text contains Arabic characters
            $hasArabic = preg_match('/[\p{Arabic}]/u', $receivedMessage . $normalizedTrigger);
            
            if ($hasArabic) {
                // For Arabic text, use simple string matching without word boundaries
                foreach ($triggerWords as $word) {
                    if (strpos($receivedMessage, $word) !== false) {
                        return true;
                    }
                }
                return false;
            } else {
                // For non-Arabic text, use case-insensitive regex approach
                $pattern = '/\b(' . implode('|', array_map('preg_quote', $triggerWords)) . ')\b/i';
                return preg_match($pattern, strtolower($receivedMessage)) === 1;
            }
        }
    
        return false;
    }

    protected function sendReply(Chat $chat, AutoReply $autoreply)
    {
		logger('send replay');
        $contact = Contact::where('id', $chat->contact_id)->first();
        $organization_id = $chat->organization_id;
        $metadata = json_decode($autoreply->metadata);
        $replyType = $metadata->type;

        if($replyType === 'text'){
			logger('inside text');
            $message = $this->replacePlaceholders($organization_id, $contact->uuid, $metadata->data->text);
            $this->initializeWhatsappService($organization_id)->sendMessage($contact->uuid, $message);
        } else if($replyType === 'audio' || $replyType === 'image'){
			logger('inside audio image');
            $location = strpos($metadata->data->file->location, 'public\\') === 0 ? 'local' : 'amazon';
            $this->initializeWhatsappService($organization_id)->sendMedia($contact->uuid, $replyType, $metadata->data->file->name, $metadata->data->file->location, $metadata->data->file->url, $location);
        }
    }

    private function initializeWhatsappService($organizationId)
    {
		logger('inside initializeWhatsappService');
        $config = Organization::where('id', $organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        return new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);
    }

    private function replacePlaceholders($organizationId, $contactUuid, $message){
		logger('replace placeholders');
        $organization = Organization::where('id', $organizationId)->first();
        $contact = Contact::with('contactGroups')->where('uuid', $contactUuid)->first();
        $address = $contact->address ? json_decode($contact->address, true) : [];
        $metadata = $contact->metadata ? json_decode($contact->metadata, true) : [];
        $full_address = ($address['street'] ?? Null) . ', ' .
                        ($address['city'] ?? Null) . ', ' .
                        ($address['state'] ?? Null) . ', ' .
                        ($address['zip'] ?? Null) . ', ' .
                        ($address['country'] ?? Null);

        $data = [
            'first_name' => $contact->first_name ?? Null,
            'last_name' => $contact->last_name ?? Null,
            'full_name' => $contact->full_name ?? Null,
            'email' => $contact->email ?? Null,
            'phone' => $contact->phone ?? Null,
            'organization_name' => $organization->name,
            'full_address' => $full_address,
            'street' => $address['street'] ?? Null,
            'city' => $address['city'] ?? Null,
            'state' => $address['state'] ?? Null,
            'zip_code' => $address['zip'] ?? Null,
            'country' => $address['country'] ?? Null,
        ];

        $transformedMetadata = [];
        if($metadata){
            foreach ($metadata as $key => $value) {
                $transformedKey = strtolower(str_replace(' ', '_', $key));
                $transformedMetadata[$transformedKey] = $value;
            }
        }

        $mergedData = array_merge($data, $transformedMetadata);

        //Log::info($mergedData);

        // First handle URL-encoded placeholders with {url:placeholder} syntax
        $message = preg_replace_callback('/\{url:(\w+)\}/', function ($matches) use ($mergedData) {
            $key = $matches[1];
            if (isset($mergedData[$key])) {
                return rawurlencode($mergedData[$key]);
            }
            return $matches[0];
        }, $message);
        
        // Then handle regular placeholders
        return preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($mergedData) {
            $key = $matches[1];
            return isset($mergedData[$key]) ? $mergedData[$key] : $matches[0];
        }, $message);
    }
}
