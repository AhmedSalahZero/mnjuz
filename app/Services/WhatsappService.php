<?php

namespace App\Services;

use App\Events\NewChatEvent;
use App\Helpers\DateTimeHelper;
use App\Helpers\WebhookHelper;
use App\Models\Campaign;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatMedia;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Session;

class WhatsappService
{
    private $accessToken;
    private $apiVersion;
    private $appId;
    private $phoneNumberId;
    private $organizationId;
    private $wabaId;

    public function __construct($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId)
    {
        $this->accessToken = $accessToken;
        $this->apiVersion = $apiVersion;
        $this->appId = $appId;
        $this->phoneNumberId = $phoneNumberId;
        $this->wabaId = $wabaId;
        $this->organizationId = $organizationId;

        Config::set('broadcasting.connections.pusher', [
            'driver' => 'pusher',
            'key' => Setting::where('key', 'pusher_app_key')->first()->value,
            'secret' => Setting::where('key', 'pusher_app_secret')->first()->value,
            'app_id' => Setting::where('key', 'pusher_app_id')->first()->value,
            'options' => [
                'cluster' => Setting::where('key', 'pusher_app_cluster')->first()->value,
            ],
        ]);
    }

    /**
     * This function sends a text message via a POST request to the specified phone number using Facebook's messaging API.
     *
     * @param string $phoneNumber The phone number of the recipient.
     * @param string $messageContent The content of the message to be sent.
     * @return mixed Returns the response from the HTTP request.
     */
    public function sendMessage($contactUuId, $messageContent, $userId = NULL, $type="text", $buttons = [], $header = [], $footer = null, $buttonLabel = null)
    {
		logger('send message WhatsappService');
        $contact = Contact::where('uuid', $contactUuId)->first();
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        
        $headers = $this->setHeaders();

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['recipient_type'] = 'individual';
        $requestData['to'] = $contact->phone;
        if($type == "text"){
            $requestData['type'] = 'text';
            $requestData['text']['preview_url'] = true; //If you have added url either http or https a preview will be displayed
            $requestData['text']['body'] = clean($messageContent);
        } else if($type == "interactive buttons" || $type == "interactive call to action url" || $type == "interactive list"){
            $requestData['type'] = 'interactive';

            if($type == "interactive buttons"){
                $requestData['interactive']['type'] = 'button';
            } else if($type == "interactive call to action url"){
                $requestData['interactive']['type'] = 'cta_url';
            } else if($type == "interactive list"){
                $requestData['interactive']['type'] = 'list';
            }

            if($type == "interactive buttons"){
                foreach($buttons as $button){
                    $requestData['interactive']['action']['buttons'][] = [
                        'type' => 'reply',
                        'reply' => [
                            'id' => $button['id'],
                            'title' => $button['title'],
                        ],
                    ];
                }
            } else if($type == "interactive call to action url"){
                $requestData['interactive']['action']['name'] = "cta_url";
                $requestData['interactive']['action']['parameters'] = $buttons;
            } else if($type == "interactive list"){
                $requestData['interactive']['action']['sections'] = $buttons;
                $requestData['interactive']['action']['button'] = $buttonLabel;
            }

            if (!empty($header)) {
                $requestData['interactive']['header'] = $header;
            }

            $requestData['interactive']['body']['text'] = clean($messageContent);

            if ($footer != null) {
                $requestData['interactive']['footer'] = [
                    'text' => clean($footer),
                ];
            }
        }

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);

        if($responseObject->success === true){
            $response['text']['body'] = clean($messageContent);
            $response['type'] = 'text';

            $chat = Chat::create([
                'organization_id' => $contact->organization_id,
                'wam_id' => $responseObject->data->messages[0]->id,
                'contact_id' => $contact->id,
                'type' => 'outbound',
                'user_id' => $userId,
                'metadata' => json_encode($response),
                'status' => 'delivered',
				'created_at'=>now()
            ]);

            $chat = Chat::with('contact','media')->where('id', $chat->id)->first();
            $responseObject->data->chat = $chat;

            $chatlogId = ChatLog::insertGetId([
                'contact_id' => $contact->id,
                'entity_type' => 'chat',
                'entity_id' => $chat->id,
                'created_at' =>now()
            ]);

            $chatLogArray = ChatLog::where('id', $chatlogId)->where('deleted_at', null)->first();
            $chatArray = array([
                'type' => 'chat',
                'value' => $chatLogArray->relatedEntities
            ]);
            
            event(new NewChatEvent($chatArray, $contact->organization_id));
        }

        // Trigger webhook
        WebhookHelper::triggerWebhookEvent('message.sent', [
            'data' => $responseObject,
        ], $contact->organization_id);

        return $responseObject;
    }

    /**
     * This function sends a text message via a POST request to the specified phone number using Facebook's messaging API.
     *
     * @param string $phoneNumber The phone number of the recipient.
     * @param string $messageContent The content of the message to be sent.
     * @return mixed Returns the response from the HTTP request.
     */
    public function sendTemplateMessage($contactUuId, $templateContent, $userId = NULL, $campaignId = NULL, $mediaId = NULL)
    {

        $contact = Contact::where('uuid', $contactUuId)->first();
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        
        $headers = $this->setHeaders();

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['recipient_type'] = 'individual';
        $requestData['to'] = $contact->phone;
        $requestData['type'] = 'template';
        $requestData['template'] = $templateContent;

        //\Log::info('WhatsApp API Request: ' . json_encode($requestData));

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);

        if($responseObject->success === true){
            if($campaignId != NULL){
                $campaign = Campaign::where('id', $campaignId)->first();
                $templateMetadata = json_decode($campaign->metadata);
            }

            $chat = Chat::create([
                'organization_id' => $contact->organization_id,
                'wam_id' => $responseObject->data->messages[0]->id,
                'contact_id' => $contact->id,
                'type' => 'outbound',
                'user_id' => $userId,
                'metadata' => $campaignId != NULL ? $this->buildCampaignTemplateChatMessage($templateMetadata, $contactUuId) : $this->buildTemplateChatMessage($templateContent, $contact),
                'media_id' => $campaignId != NULL ? $this->getMediaIdFromCampaign($campaignId) : $mediaId,
                'status' => isset($responseObject->data->messages[0]->message_status) ? $responseObject->data->messages[0]->message_status : 'sent',
                'created_at' => now()
            ]);



            $responseObject->data->chat = $chat;

            $chatlogId = ChatLog::insertGetId([
                'contact_id' => $contact->id,
                'entity_type' => 'chat',
                'entity_id' => $chat->id,
                'created_at' =>now()
            ]);

            $chatLogArray = ChatLog::where('id', $chatlogId)->where('deleted_at', null)->first();
            $chatArray = array([
                'type' => 'chat',
                'value' => $chatLogArray->relatedEntities
            ]);

            event(new NewChatEvent($chatArray, $contact->organization_id));
        }

        // Trigger webhook
        WebhookHelper::triggerWebhookEvent('message.sent', [
            'data' => $responseObject,
        ], $contact->organization_id);

        return $responseObject;
    }

    private function getMediaIdFromCampaign($campaignId){
        $campaign = Campaign::where('id', $campaignId)->first();
        $templateMetadata = json_decode($campaign->metadata);
        $mediaId = null;

        if(isset($templateMetadata->media)){
            $mediaId = $templateMetadata->media;
        }

        return $mediaId;
    }

    private function buildCampaignTemplateChatMessage($templateMetadata, $contactUuId){
        $contact = Contact::where('uuid', $contactUuId)->first();
        $array = [];
        
        if($templateMetadata->header->format == 'IMAGE' || $templateMetadata->header->format == 'VIDEO' || $templateMetadata->header->format == 'DOCUMENT' || $templateMetadata->header->format == 'LOCATION'){
            $array['type'] = strtolower($templateMetadata->header->format);
        } else {
            $array['type'] = 'text';
        }

        //HEADER
        if(isset($templateMetadata->header->text)){
            $headerText = $templateMetadata->header->text;

            if (isset($templateMetadata->header->parameters) && !empty($templateMetadata->header->parameters)) {
                $headerParameters = $templateMetadata->header->parameters;

                if($headerParameters && count($headerParameters) >= 1){
                    foreach($headerParameters as $index => $parameter){
                        $placeholder = '{{' . ($index + 1) . '}}';
                        $value = $parameter->selection === 'static' ? $parameter->value : $this->getParameters($contact, $parameter->value);

                        $headerText = str_replace($placeholder, $value, $headerText);
                    }
                }
            }

            $array['header']['text'] = $headerText;
        }

        //BODY
        if(isset($templateMetadata->body->text)){
            $bodyText = $templateMetadata->body->text;



            if (isset($templateMetadata->body->parameters) && !empty($templateMetadata->body->parameters)) {
                $bodyParameters = $templateMetadata->body->parameters;



                if($bodyParameters && count($bodyParameters) >= 1){
                    foreach($bodyParameters as $index => $parameter){
                        $placeholder = '{{' . ($index + 1) . '}}';
                        $value = $parameter->selection === 'static' ? $parameter->value : $this->getParameters($contact, $parameter->value);



                        $bodyText = str_replace($placeholder, $value, $bodyText);
                    }
                }


            }

            if($array['type'] == 'text'){
                $array[$array['type']]['body'] = $bodyText;
            } else {
                $array[$array['type']]['caption'] = $bodyText;
            }
        }

        //FOOTER
        if(isset($templateMetadata->footer->text)){
            $array[$array['type']]['footer'] = $templateMetadata->footer->text;
        }

        //BUTTONS
        if(isset($templateMetadata->buttons)){
            foreach($templateMetadata->buttons as $key => $button){
                $array['buttons'][$key]['type'] = $button->type;
                $array['buttons'][$key]['text'] = $button->text;
                $array['buttons'][$key]['value'] = $button->value;

                if(isset($button->parameters)){
                    $array['buttons'][$key]['parameters'] = $button->parameters;
                }
            }
        }

        //dd(json_encode($array));
        return json_encode($array);
    }

    private function buildTemplateChatMessage($templateContent, $contact){
        //Get the template
        $template = Template::where('organization_id', $contact->organization_id)
            ->where('name', $templateContent['name'])
            ->where('language', $templateContent['language']['code'])
            ->first();



        $template = json_decode($template->metadata);
        $templateMetadatas = $template->components;
        $array = [];
        $array['type'] = 'text';

        foreach($templateMetadatas as $templateMetadata){
            if($templateMetadata->type == 'HEADER'){
                if($templateMetadata->format == 'IMAGE' || $templateMetadata->format == 'VIDEO' || $templateMetadata->format == 'DOCUMENT' || $templateMetadata->format == 'LOCATION'){
                    $array['type'] = strtolower($templateMetadata->format);
                } else if($templateMetadata->format == 'TEXT' && isset($templateMetadata->text)){
                    $headerText = $templateMetadata->text;

                    // Check for parameters in templateContent components
                    if (isset($templateContent['components']) && !empty($templateContent['components'])) {
                        foreach($templateContent['components'] as $component) {
                            if ($component['type'] === 'header' && isset($component['parameters'])) {
                                $headerParameters = $component['parameters'];

                                if($headerParameters && count($headerParameters) >= 1){
                                    foreach($headerParameters as $index => $parameter){
                                        $placeholder = '{{' . ($index + 1) . '}}';
                                        $value = $parameter['type'] === 'text' ? $parameter['text'] : $this->getParameters($contact, $parameter['text']);

                                        $headerText = str_replace($placeholder, $value, $headerText);
                                    }
                                }

                                break; // Found header parameters, no need to continue
                            }
                        }
                    }

                    $array['header']['text'] = $headerText;
                }
            }

            //BODY
            if($templateMetadata->type == 'BODY'){
                if(isset($templateMetadata->text)){
                    $bodyText = $templateMetadata->text;



                    // Check for parameters in templateContent components
                    if (isset($templateContent['components']) && !empty($templateContent['components'])) {
                        foreach($templateContent['components'] as $component) {
                            if ($component['type'] === 'body' && isset($component['parameters'])) {
                                $bodyParameters = $component['parameters'];
                                


                                if($bodyParameters && count($bodyParameters) >= 1){
                                    foreach($bodyParameters as $index => $parameter){
                                        $placeholder = '{{' . ($index + 1) . '}}';
                                        $value = $parameter['type'] === 'text' ? $parameter['text'] : $this->getParameters($contact, $parameter['text']);



                                        $bodyText = str_replace($placeholder, $value, $bodyText);
                                    }
                                }


                                break; // Found body parameters, no need to continue
                            }
                        }
                    }

                    if($array['type'] == 'text'){
                        $array[$array['type']]['body'] = $bodyText;
                    } else {
                        $array[$array['type']]['caption'] = $bodyText;
                    }
                }
            }

            //FOOTER
            if($templateMetadata->type == 'FOOTER'){
                $array[$array['type']]['footer'] = $templateMetadata->text;
            }

            //BUTTONS
            if($templateMetadata->type == 'BUTTONS'){
                foreach($templateMetadata->buttons as $key => $button){
                    $array['buttons'][$key]['type'] = $button->type;
                    $array['buttons'][$key]['text'] = $button->text;
                    $array['buttons'][$key]['value'] = $button->text;
    
                    if(isset($button->parameters)){
                        $array['buttons'][$key]['parameters'] = $button->parameters;
                    }
                }
            }
        }

        //\Log::info(json_encode($array));
        return json_encode($array);
    }

    private function getParameters($contact, $parameter){
        if($parameter === 'first name'){
            return $contact->first_name;
        } else if($parameter === 'last name'){
            return $contact->last_name;
        } else if($parameter === 'name'){
            return $contact->first_name . ' ' . $contact->last_name;
        } else if($parameter === 'email'){
            return $contact->email;
        } else if($parameter === 'phone'){
            return $contact->phone;
        }
    }

    /**
     * This function sends media content via a POST request and uploads the media to Facebook's resumable API.
     * Note that media types can only be audio, document, image, sticker, or video.
     *
     * @param string $phoneNumber The phone number of the recipient.
     * @param string $mediaType The type of media being uploaded. Valid options are audio, document, image, sticker, or video.
     * @param string $mediaFile The file to be uploaded as media.
     * @return mixed Returns the response from the HTTP request.
     */
    /*public function sendMedia($contactUuid, $mediaType, $mediaFile)
    {
        $contact = Contact::where('uuid', $contactUuId)->first();
        $mediaFilePath = Storage::path("media/{$mediaFileName}");

        $fileUploadResponse = $this->initiateResumableUploadSession($mediaFilePath);

        if(!$fileUploadResponse->success){
            return $fileUploadResponse;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        $headers = $this->setHeaders();

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['recipient_type'] = 'individual';
        $requestData['to'] = $contact->phone;
        $requestData['type'] = $mediaType;
        $requestData[$mediaType]['id'] = $fileUploadResponse->data->h;

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);

        dd($responseObject);
    }*/

    /**
     * This function sends a stored image as a media file via a POST request to the specified phone number using Facebook's messaging API.
     *
     * @param string $contactUuId The UUID of the contact to whom the image will be sent.
     * @param string $imageUrl The URL of the stored image.
     * @return mixed Returns the response from the HTTP request.
     */
    public function sendMedia($contactUuId, $mediaType, $mediaFileName, $mediaFilePath, $mediaUrl, $location, $caption = NULL, $transcription = NULL)
    {
	//	logger('sendMedia WhatsappService');
        $contact = Contact::where('uuid', $contactUuId)->first();
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        
        $headers = $this->setHeaders();

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['recipient_type'] = 'individual';
        $requestData['to'] = $contact->phone;
        $requestData['type'] = $mediaType;
        $requestData[$mediaType]['link'] = $mediaUrl;

        if($mediaType == 'document'){
            $requestData[$mediaType]['filename'] = $mediaFileName;
        }

        if($caption != NULL && $mediaType != 'audio'){
            $requestData[$mediaType]['caption'] = $caption;
        }

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);

        //Log::info(json_encode($responseObject));

        if($responseObject->success === true){
            //Log::info($mediaUrl);
            $wamId = $responseObject->data->messages[0]->id;
            $contentType = $this->getContentTypeFromUrl($mediaUrl);
            $mediaData = $this->formatMediaResponse($wamId, $mediaUrl, $mediaType, $contentType, $transcription);
            $mediaSize = $this->getMediaSizeInBytesFromUrl($mediaUrl);

            $chat = Chat::create([
                'organization_id' => $contact->organization_id,
                'wam_id' => $wamId,
                'contact_id' => $contact->id,
                'type' => 'outbound',
                'metadata' => json_encode($mediaData),
                'status' => 'sent',
				'created_at'=>now()
            ]);

            $chatlogId = ChatLog::insertGetId([
                'contact_id' => $contact->id,
                'entity_type' => 'chat',
                'entity_id' => $chat->id,
                'created_at' => now()
            ]);

            $media = ChatMedia::create([
                'name' => $mediaFileName,
                'path' => $mediaUrl,
                'location' => $location,
                'type' => $contentType,
                'size' => $mediaSize,
				 'created_at' => now()
            ]);

            Chat::where('id', $chat->id)->update([
                'media_id' => $media->id
            ]);

            $chat = Chat::with('contact','media')->where('id', $chat->id)->first();
            $responseObject->data->chat = $chat;

            $chatLogArray = ChatLog::where('id', $chatlogId)->where('deleted_at', null)->first();
            $chatArray = array([
                'type' => 'chat',
                'value' => $chatLogArray->relatedEntities
            ]);

            event(new NewChatEvent($chatArray, $contact->organization_id));
        }

        //\Log::info(json_encode($responseObject, true));

        // Trigger webhook
        WebhookHelper::triggerWebhookEvent('message.sent', [
            'data' => $responseObject,
        ], $contact->organization_id);

        return $responseObject;
    }

    function getContentTypeFromUrl($url) {
        try {
            // Make a HEAD request to fetch headers only
            $response = Http::head($url);
    
            // Check if the Content-Type header is present
            if ($response->hasHeader('Content-Type')) {
                return $response->header('Content-Type');
            }
    
            return null;
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Error fetching headers: ' . $e->getMessage());
            return null;
        }
    }

    function formatMediaResponse($wamId, $mediaUrl, $mediaType, $contentType, $transcription = null){
        $response = [
            "id" => $wamId,
            "type" => $mediaType,
            $mediaType => [
                "mime_type" => $contentType,
            ]
        ];

        if ($mediaType === 'audio' && $transcription) {
            $response['transcript'] = $transcription;
        }

        return $response;
    }

    function getMediaSizeInBytesFromUrl($url) {
        $imageContent = file_get_contents($url);
    
        if ($imageContent !== false) {
            return strlen($imageContent);
        }
    
        return null;
    }

    /**
     * This function allows you to react to a specific message with an emoji via a POST request to Facebook's messaging API.
     *
     * @param string $phoneNumber The phone number of the recipient.
     * @param string $wamId The ID of the message you want to react to.
     * @param string $emoji The emoji you want to use as a reaction.
     * @return mixed Returns the response from the HTTP request.
     */
    public function reactToMessage($phoneNumber, $wamId, $emoji)
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        $headers = $this->setHeaders();

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['recipient_type'] = 'individual';
        $requestData['to'] = $phoneNumber;
        $requestData['type'] = 'reaction';
        $requestData['reaction']['message_id'] = $wamId;
        $requestData['reaction']['emoji'] = $emoji;

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);

        dd($responseObject);
    }

    /**
     * This function sends a location to a specific phone number via a POST request using Facebook's messaging API.
     *
     * @param string $phoneNumber The phone number of the recipient.
     * @param object $location The location object containing longitude, latitude, name, and address.
     * @return mixed Returns the response from the HTTP request.
     */
    public function sendLocation($phoneNumber, $location)
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        $headers = $this->setHeaders();

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['to'] = $phoneNumber;
        $requestData['type'] = 'location';
        $requestData['location']['longitude'] = $location->longitude;
        $requestData['location']['latitude'] = $location->latitude;
        $requestData['location']['name'] = $location->name;
        $requestData['location']['address'] = $location->address;

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);

        dd($responseObject);
    }

    public function createTemplate(Request $request)
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/message_templates";
        
        $requestData = [
            "name" => $request->name,
            "language" => $request->language,
            "category" => $request->category,
        ];

        if($request->customize_ttl && $request->message_send_ttl_seconds){
            $requestData['message_send_ttl_seconds'] = $request->message_send_ttl_seconds;
        }

        if($request->category != 'AUTHENTICATION'){
            if($request->header['format'] === 'TEXT'){
                if(isset($request->header['text'])){
                    $headerComponent = [];

                    $headerComponent['type'] = "HEADER";
                    $headerComponent['format'] = $request->header['format'];
                    $headerComponent['text'] = $request->header['text'];

                    if (!empty($request->header['example'])) {
                        $headerComponent['example']['header_text'] = $request->header['example'];
                    }

                    $requestData['components'][] = $headerComponent;
                }
            }
        

            if(($request->header['format'] === 'IMAGE' || $request->header['format'] === 'VIDEO' || $request->header['format'] === 'DOCUMENT')){
                if(isset($request->header['example'])){
                    $fileUploadResponse = $this->initiateResumableUploadSession($request->header['example']);

                    if(!$fileUploadResponse->success){
                        return $fileUploadResponse;
                    }

                    $requestData['components'][] = [
                        "type" => "HEADER",
                        "format" => $request->header['format'],
                        "example" => [
                            "header_handle" => [
                                $fileUploadResponse->data->h
                            ]
                        ]
                    ];
                }
            }
        }
        
        if($request->category == 'AUTHENTICATION'){
            $bodyComponent = [];
            $bodyComponent['type'] = "BODY";
            $bodyComponent['add_security_recommendation'] = $request->body['add_security_recommendation'];

            $requestData['components'][] = $bodyComponent;
        } else {
            $bodyComponent = [];

            if($request->body['text'] != null){
                $bodyComponent['type'] = "BODY";
                $bodyComponent['text'] = $request->body['text'];

                if (!empty($request->body['example'])) {
                    $bodyComponent['example']['body_text'][] = $request->body['example'];
                }

                $requestData['components'][] = $bodyComponent;
            }
        }

        if ($request->has('footer')) {
            if($request->category != 'AUTHENTICATION'){
                if(isset($request->footer['text']) &&  $request->footer['text'] != null){
                    $requestData['components'][] = [
                        "type" => "FOOTER",
                        "text" => $request->footer['text']
                    ];
                }
            } else {
                $requestData['components'][] = [
                    "type" => "FOOTER",
                    "code_expiration_minutes" => $request->footer['code_expiration_minutes']
                ];
            }
        }

        if($request->category != 'AUTHENTICATION'){
            if ($request->has('buttons')) {
                if (!isset($requestData['components'])) {
                    $requestData['components'] = [];
                }
            
                $requestData['components'][] = [
                    'type' => 'BUTTONS',
                    'buttons' => []
                ];

                $quickReplyButtons = [];

                foreach ($request->buttons as $button) {
                    if ($button['type'] === 'QUICK_REPLY') {
                        $quickReplyButtons[] = [
                            'type' => $button['type'],
                            'text' => $button['text'],
                        ];
                    }
                }
            
                foreach ($request->buttons as $button) {
                    if ($button['type'] !== 'QUICK_REPLY') {
                        if ($button['type'] === 'URL') {
                            $requestData['components'][count($requestData['components']) - 1]['buttons'][] = [
                                'type' => $button['type'],
                                'text' => $button['text'],
                                'url' => $button['url'],
                            ];
                        } elseif ($button['type'] === 'PHONE_NUMBER') {
                            $requestData['components'][count($requestData['components']) - 1]['buttons'][] = [
                                'type' => $button['type'],
                                'text' => $button['text'],
                                'phone_number' => $button['country'].$button['phone_number'],
                            ];
                        } elseif ($button['type'] === 'COPY_CODE') {
                            $requestData['components'][count($requestData['components']) - 1]['buttons'][] = [
                                'type' => $button['type'],
                                'example' => $button['example'],
                            ];
                        }
                    }
                }

                // Add the quick reply buttons at the start
                if (!empty($quickReplyButtons)) {
                    $requestData['components'][count($requestData['components']) - 1]['buttons'] = array_merge($quickReplyButtons, $requestData['components'][count($requestData['components']) - 1]['buttons']);
                }
            }
        } else {
            $button = [
                'type' => $request->authentication_button['type'],
                'otp_type' => $request->authentication_button['otp_type'],
                'text' => $request->authentication_button['text'],
            ];

            if($request->authentication_button['otp_type'] != 'copy_code'){
                $button['autofill_text'] = $request->authentication_button['autofill_text'];
                $button['supported_apps'] = $request->authentication_button['supported_apps'];
            }

            if ($request->authentication_button['otp_type'] === 'zero_tap') {
                $button['zero_tap_terms_accepted'] = $request->authentication_button['zero_tap_terms_accepted'];
            }

            $requestData['components'][] = [
                'type' => 'BUTTONS',
                'buttons' => [$button],
            ];
        }

        $client = new Client();
        $responseObject = new \stdClass();

        //\Log::info($requestData);

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestData,
            ]);

            $responseObject->success = true;
            $responseObject->data = json_decode($response->getBody()->getContents());

            //Save Template To Database
            $template = new Template();
            $template->organization_id = session()->get('current_organization');
            $template->meta_id = $responseObject->data->id;
            $template->name = $request->name;
            $template->category = $request->category;
            $template->language = $request->language;
            $template->metadata = json_encode($requestData);
            $template->status = $responseObject->data->status;
            $template->created_by = auth()->user()->id;
            $template->created_at =now();
            $template->updated_at = now();
            $template->save();
        } catch (ConnectException $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->message = $e->getMessage();
        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data = json_decode($response->getBody()->getContents());

            if (isset($responseObject->data->error->error_user_msg)) {
                $responseObject->message = $responseObject->data->error->error_user_msg;
            } else {
                $responseObject->message = $responseObject->data->error->message;
            }
        } catch (Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    public function updateTemplate(Request $request, $uuid)
    {
        $template = Template::where('uuid', $uuid)->first();
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$template->meta_id}";
        
        $requestData = [
            //"name" => $request->name,
            //"language" => $request->language,
            "category" => $template->status == 'APPROVED' ? $template->category : $request->category,
        ];

        if($request->customize_ttl && $request->message_send_ttl_seconds){
            $requestData['message_send_ttl_seconds'] = $request->message_send_ttl_seconds;
        }

        if($request->category != 'AUTHENTICATION'){
            if($request->header['format'] === 'TEXT'){
                if(isset($request->header['text'])){
                    $headerComponent = [];

                    $headerComponent['type'] = "HEADER";
                    $headerComponent['format'] = $request->header['format'];
                    $headerComponent['text'] = $request->header['text'];

                    if (!empty($request->header['example'])) {
                        $headerComponent['example']['header_text'] = $request->header['example'];
                    }

                    $requestData['components'][] = $headerComponent;
                }
            }

            if(($request->header['format'] === 'IMAGE' || $request->header['format'] === 'VIDEO' || $request->header['format'] === 'DOCUMENT')){
                if(isset($request->header['example'])){
                    $fileUploadResponse = $this->initiateResumableUploadSession($request->header['example']);

                    if(!$fileUploadResponse->success){
                        return $fileUploadResponse;
                    }

                    $requestData['components'][] = [
                        "type" => "HEADER",
                        "format" => $request->header['format'],
                        "example" => [
                            "header_handle" => [
                                $fileUploadResponse->data->h
                            ]
                        ]
                    ];
                } else {
                    // Decode existing metadata
                    $metadata = json_decode($template->metadata, true);

                    // Extract existing header if available
                    $existingHeader = [];
                    if (isset($metadata['components'])) {
                        foreach ($metadata['components'] as $component) {
                            if ($component['type'] === 'HEADER') {
                                $existingHeader = $component;
                                break;
                            }
                        }
                    }

                    $requestData['components'][] = $existingHeader;
                }
            }
        }

        if($request->category == 'AUTHENTICATION'){
            $bodyComponent = [];
            $bodyComponent['type'] = "BODY";
            $bodyComponent['add_security_recommendation'] = $request->body['add_security_recommendation'];

            $requestData['components'][] = $bodyComponent;
        } else {
            if($request->body['text'] != null){
                $bodyComponent = [];

                $bodyComponent['type'] = "BODY";
                $bodyComponent['text'] = $request->body['text'];

                if (!empty($request->body['example'])) {
                    $bodyComponent['example']['body_text'][] = $request->body['example'];
                }

                $requestData['components'][] = $bodyComponent;
            }
        }

        if ($request->has('footer')) {
            if($request->category != 'AUTHENTICATION'){
                if($request->footer['text'] != null){
                    $requestData['components'][] = [
                        "type" => "FOOTER",
                        "text" => $request->footer['text']
                    ];
                }
            } else {
                $requestData['components'][] = [
                    "type" => "FOOTER",
                    "code_expiration_minutes" => $request->footer['code_expiration_minutes']
                ];
            }
        }

        if($request->category != 'AUTHENTICATION'){
            if ($request->has('buttons')) {
                if (!isset($requestData['components'])) {
                    $requestData['components'] = [];
                }
            
                $requestData['components'][] = [
                    'type' => 'BUTTONS',
                    'buttons' => []
                ];

                $quickReplyButtons = [];

                foreach ($request->buttons as $button) {
                    if ($button['type'] === 'QUICK_REPLY') {
                        $quickReplyButtons[] = [
                            'type' => $button['type'],
                            'text' => $button['text'],
                        ];
                    }
                }
            
                foreach ($request->buttons as $button) {
                    if ($button['type'] !== 'QUICK_REPLY') {
                        if ($button['type'] === 'URL') {
                            $requestData['components'][count($requestData['components']) - 1]['buttons'][] = [
                                'type' => $button['type'],
                                'text' => $button['text'],
                                'url' => $button['url'],
                            ];
                        } elseif ($button['type'] === 'PHONE_NUMBER') {
                            $requestData['components'][count($requestData['components']) - 1]['buttons'][] = [
                                'type' => $button['type'],
                                'text' => $button['text'],
                                'phone_number' => $button['country'] ?? '' . $button['phone_number'],
                            ];
                        } elseif ($button['type'] === 'COPY_CODE') {
                            $requestData['components'][count($requestData['components']) - 1]['buttons'][] = [
                                'type' => $button['type'],
                                'example' => $button['example'],
                            ];
                        }
                    }
                }

                // Add the quick reply buttons at the start
                if (!empty($quickReplyButtons)) {
                    $requestData['components'][count($requestData['components']) - 1]['buttons'] = array_merge($quickReplyButtons, $requestData['components'][count($requestData['components']) - 1]['buttons']);
                }
            }
        } else {
            $button = [
                'type' => $request->authentication_button['type'],
                'otp_type' => $request->authentication_button['otp_type'],
                'text' => $request->authentication_button['text'],
            ];

            if($request->authentication_button['otp_type'] != 'copy_code'){
                $button['autofill_text'] = $request->authentication_button['autofill_text'];
                $button['supported_apps'] = $request->authentication_button['supported_apps'];
            }

            if ($request->authentication_button['otp_type'] === 'zero_tap') {
                $button['zero_tap_terms_accepted'] = $request->authentication_button['zero_tap_terms_accepted'];
            }

            $requestData['components'][] = [
                'type' => 'BUTTONS',
                'buttons' => [$button],
            ];
        }

        $client = new Client();
        $responseObject = new \stdClass();

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestData,
            ]);

            $responseObject->success = true;
            $responseObject->data = json_decode($response->getBody()->getContents());

            //Update Template In Database
            if ($template) {
                $template->organization_id = session()->get('current_organization');
                $template->category = $template->status == 'APPROVED' ? $template->category : $request->category;
                //$template->metadata = json_encode($requestData);
                $template->status = 'PENDING';
                $template->created_by = auth()->user()->id;
                $template->updated_at = now(); // No need to set `created_at` when updating
                $template->save();
            } else {
                // Handle case where template is not found (optional)
                throw new \Exception('Template not found');
            }
        } catch (ConnectException $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->message = $e->getMessage();
        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data = json_decode($response->getBody()->getContents());

            if (isset($responseObject->data->error->error_user_msg)) {
                $responseObject->message = $responseObject->data->error->error_user_msg;
            } else {
                $responseObject->message = $responseObject->data->error->message;
            }
        } catch (Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    function syncTemplates()
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/message_templates";

        $client = new Client();
        $responseObject = new \stdClass();

        try {
            do {
                $response = $client->request('GET', $url, [
                    'headers' => [
                        'Authorization' => "OAuth {$this->accessToken}",
                    ],
                ]);

                $responseObject = json_decode($response->getBody()->getContents());

                //dd($responseObject);

                foreach($responseObject->data as $templateData){
                    $template = Template::where('organization_id', session()->get('current_organization'))
                        ->where('meta_id', $templateData->id)->first();

                    if($template){
                        $template->metadata = json_encode($templateData);
                        $template->status = $templateData->status;
                        $template->updated_at = now();
                        $template->deleted_at = NULL;
                        $template->save();
                    } else {
                        $template = new Template();
                        $template->organization_id = session()->get('current_organization');
                        $template->meta_id = $templateData->id;
                        $template->name = $templateData->name;
                        $template->category = $templateData->category;
                        $template->language = $templateData->language;
                        $template->metadata = json_encode($templateData);
                        $template->status = $templateData->status;
                        $template->created_by = auth()->user()->id;
                        $template->created_at = now();
                        $template->updated_at = now();
                        $template->save();
                    }
                };

                if(isset($responseObject->paging) && isset($responseObject->paging->next)) {
                    $url = $responseObject->paging->next;
                } else {
                    $url = null; // Break the loop if no next page URL is available
                }
            } while($url);
        } catch (ConnectException $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data = json_decode($response->getBody()->getContents());

            if (isset($responseObject->data->error->error_user_msg)) {
                $responseObject->message = $responseObject->data->error->error_user_msg;
            } else {
                $responseObject->message = $responseObject->data->error->message;
            }
        } catch (Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    /**
     * This function deletes a template by its UUID via a DELETE request to Facebook's messaging API.
     *
     * @param string $uuid The UUID of the template to be deleted.
     * @return mixed Returns the response from the HTTP request.
     */
    public function deleteTemplate($uuid)
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/message_templates";
        $headers = $this->setHeaders();

        $template = Template::where('uuid', $uuid)->first();

        $requestData['hsm_id'] = $template->meta_id;
        $requestData['name'] = $template->name;

        $responseObject = $this->sendHttpRequest('DELETE', $url, $requestData, $headers);

        if($responseObject->success){
            $template->deleted_at = now();
            $template->save();
        }

        return $responseObject;
    }

    function getMedia($mediaId)
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$mediaId}";
        $headers = $this->setHeaders();

        $responseObject = $this->sendHttpRequest('GET', $url, NULL, $headers);

        return $responseObject;
    }

    function checkHealth()
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}?fields=health_status";
        $headers = $this->setHeaders();

        $responseObject = $this->sendHttpRequest('GET', $url, NULL, $headers);

        return $responseObject;
    }

    function subscribeToWaba()
    {
        $responseObject = new \stdClass();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken
            ])->post("https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/subscribed_apps")->throw()->json();

            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    function getWabaSubscriptions()
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/subscribed_apps";
        $headers = $this->setHeaders();

        $responseObject = $this->sendHttpRequest('GET', $url, NULL, $headers);

        return $responseObject;
    }

    function overrideCallbackUrl($callbackUrl, $verifyToken)
    {
        $responseObject = new \stdClass();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken
            ])->post("https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/subscribed_apps", [
                'override_callback_uri' => $callbackUrl,
                'verify_token' => $verifyToken
            ])->throw()->json();

            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    function unSubscribeToWaba()
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/subscribed_apps";
        $headers = $this->setHeaders();

        $responseObject = $this->sendHttpRequest('DELETE', $url, NULL, $headers);

        return $responseObject;
    }

    public function getBusinessProfile(){
        $responseObject = new \stdClass();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken
            ])->get("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/whatsapp_business_profile", [
                'fields' => 'about,address,description,email,profile_picture_url,websites,vertical',
            ])->throw()->json();

            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response['data'][0];
            }
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    public function updateBusinessProfile(Request $request){
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/whatsapp_business_profile";
        
        $headers = $this->setHeaders();

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['about'] = $request->about;
        $requestData['address'] = $request->address;
        $requestData['description'] = $request->description;
        $requestData['vertical'] = $request->industry;
        $requestData['email'] = $request->email;
            
        $profile_picture_url = NULL;

        if($request->hasFile('profile_picture_url')){
            $storage = Setting::where('key', 'storage_system')->first()->value;
            $fileContent = $request->file('profile_picture_url');

            if($storage === 'local'){
                $file = Storage::disk('local')->put('public', $fileContent);
                $mediaFilePath = $file;
                $profile_picture_url = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
            } else if($storage === 'aws') {
                $file = $request->file('profile_picture_url');
                $uploadedFile = $file->store('uploads/media/sent/' . $this->organizationId, 's3');
                $mediaFilePath = Storage::disk('s3')->url($uploadedFile);
                $profile_picture_url = $mediaFilePath;
            }

            $fileUploadResponse = $this->initiateResumableUploadSession($request->file('profile_picture_url'));

            if($fileUploadResponse->success){
                $requestData['profile_picture_handle'] = $fileUploadResponse->data->h;
            }
        }

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);

        if($responseObject->success === true){
            $organizationConfig = Organization::where('id', $this->organizationId)->first();
            $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

            $metadataArray['whatsapp']['business_profile']['about'] = $request->about;
            $metadataArray['whatsapp']['business_profile']['address'] = $request->address;
            $metadataArray['whatsapp']['business_profile']['description'] = $request->description;
            $metadataArray['whatsapp']['business_profile']['industry'] = $request->industry;
            $metadataArray['whatsapp']['business_profile']['email'] = $request->email;
            if($profile_picture_url != NULL){
                $metadataArray['whatsapp']['business_profile']['profile_picture_url'] = $profile_picture_url;
            }

            $updatedMetadataJson = json_encode($metadataArray);

            $organizationConfig->metadata = $updatedMetadataJson;
            $organizationConfig->save();
        }

        return $responseObject;
    }

    public function deRegisterPhone(){
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/deregister";
        
        $headers = $this->setHeaders();

        $responseObject = $this->sendHttpRequest('POST', $url, NULL, $headers);

        if($responseObject->success === true){
            dd($responseObject);
        }

        dd($responseObject);
        return $responseObject;
    }

    public function getPhoneNumberId(){
        $responseObject = new \stdClass();

        try {
            $fields = 'display_phone_number,certificate,name_status,new_certificate,new_name_status,verified_name,quality_rating,messaging_limit_tier';

            $response = Http::get("https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/phone_numbers", [
                'fields' => $fields,
                'access_token' => $this->accessToken,
            ])->throw()->json();

            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response['data'][0];
            }
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    public function getPhoneNumberStatus(){
        $responseObject = new \stdClass();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken
            ])->get("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}", [
                'fields' => 'status, code_verification_status , quality_score, health_status',
            ])->throw()->json();

            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response;
            }
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    public function getAccountReviewStatus(){
        $responseObject = new \stdClass();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken
            ])->get("https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}", [
                'fields' => 'account_review_status',
            ])->throw()->json();

            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response;
            }
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    function viewMedia($mediaId)
    {
        $response = $this->getMedia($mediaId);

        if(!$response->success){
            return $response;
        }

        $url = $response->data->url;
        $headers = $this->setHeaders();

        $responseObject = $this->sendHttpRequest('GET', $url, NULL, $headers);

        dd($responseObject);

        return $responseObject;
    }

    function initiateResumableUploadSession($file)
    {
        $sessionResponse = $this->createResumableUploadSession($file);

        if(!$sessionResponse->success){
            return $sessionResponse;
        }

        $uploadSessionId = $sessionResponse->data->id;
        $fileName = $file->getPathname();
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$uploadSessionId}";

        $client = new Client();
        $responseObject = new \stdClass();

        try {
            $response = $client->request('POST', $url, [
                'headers' => [
                    'Authorization' => "OAuth {$this->accessToken}",
                    'file_offset' => 0,
                ],
                'body' => fopen($fileName, 'r'),
                'timeout' => 2,
            ]);

            $responseObject->success = true;
            $responseObject->data = json_decode($response->getBody()->getContents());
        } catch (ConnectException $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data = json_decode($response->getBody()->getContents());

            if (isset($responseObject->data->error->error_user_msg)) {
                $responseObject->message = $responseObject->data->error->error_user_msg;
            } else {
                $responseObject->message = $responseObject->data->error->message;
            }
        } catch (Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    function createResumableUploadSession($file)
    {
        $fileLength = $file->getSize();
        $fileType = $file->getMimeType();
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->appId}/uploads";

        $client = new Client();
        $responseObject = new \stdClass();

        try {
            $response = $client->request('POST', $url, [
                'form_params' => [
                    'file_length' => $fileLength,
                    'file_type' => $fileType,
                    'access_token' => $this->accessToken,
                ]
            ]);
        
            $status = $response->getStatusCode();
            $responseObject->success = true;
            $responseObject->data = json_decode($response->getBody()->getContents());
        } catch (ConnectException $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data = json_decode($response->getBody()->getContents());

            if (isset($responseObject->data->error->error_user_msg)) {
                $responseObject->message = $responseObject->data->error->error_user_msg;
            } else {
                $responseObject->message = $responseObject->data->error->message;
            }
        } catch (Exception $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data = json_decode($response->getBody()->getContents());
        }

        return $responseObject;
    }

    //Set the headers for request
    public function setHeaders()
    {
        return [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/json',
        ];
    }

    // Private method to send an HTTP request
    private function sendHttpRequest($method, $url, $data = [], $headers = [])
    {
        $client = new Client();
        $responseObject = new \stdClass();

        try {
            $requestOptions = [
                'headers' => $headers,
            ];

            if (isset($data) && $method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
                $requestOptions['json'] = $data;
            }

            $response = $client->request($method, $url, $requestOptions);
            $responseObject->success = true;
            $responseObject->data = json_decode($response->getBody()->getContents());
        } catch (ConnectException $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data = json_decode($response->getBody()->getContents());

            if (isset($responseObject->data->error->error_user_msg)) {
                $responseObject->message = $responseObject->data->error->error_user_msg;
            } else {
                $responseObject->message = $responseObject->data->error->message;
            }
        } catch (Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }
	
	
	
}
