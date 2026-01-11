<?php

namespace App\Services;

use App\Events\NewChatEvent;
use App\Helpers\CustomHelper;
use App\Helpers\DateTimeHelper;
use App\Http\Resources\ContactResource;
use App\Models\Addon;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatMedia;
use App\Models\ChatTicket;
use App\Models\ChatTicketLog;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\Template;
use App\Services\SubscriptionService;
use App\Services\WhatsappService;
use App\Traits\TemplateTrait;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ChatService
{
    use TemplateTrait;

    private $whatsappService;
    private $organizationId;

    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
        $this->initializeWhatsappService();
    }

    private function initializeWhatsappService()
    {
    
        $config = Organization::where('id', $this->organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $this->organizationId);
    }

    public function getChatList($request, $uuid = null, $searchTerm = null)
    {
        //	$uuid = 'b27a5a63-05d4-4e2f-911c-4da76044328c';
        $role = auth()->user()->teams[0]->role;
        $contact = new Contact;
        $config = Organization::find($this->organizationId);
        $ticketState = $request->status == null ? 'all' : $request->status;
        $sortDirection = $request->session()->get('chat_sort_direction') ?? 'desc';
        $allowAgentsToViewAllChats = true;
        $ticketingActive = false;
        $aimodule = CustomHelper::isModuleEnabled('AI Assistant');

        //Check if tickets module has been enabled
        
        if ($config->metadata != null) {
            $settings = json_decode($config->metadata);
            if (isset($settings->tickets) && $settings->tickets->active === true) {
                $ticketingActive = true;
                $this->ensureChatTicketsExist();
                //Check for chats that don't have corresponding chat ticket rows
                // $contacts = $contact->contactsWithChats($this->organizationId, NULL);
            
                // foreach($contacts as $contact){
                //     ChatTicket::firstOrCreate(
                //         ['contact_id' => $contact->id],
                //         [
                //             'assigned_to' => null,
                //             'status' => 'open',
                //             'updated_at' => now(),
                //         ]
                //     );
                // }
        
                //Check if agents can view all chats
                $allowAgentsToViewAllChats = $settings->tickets->allow_agents_to_view_all_chats;
            }
        }
        /**
         * @var Contact $contact
         */
        // Retrieve the list of contacts with chats
        // $contacts = $contact->contactsWithChats($this->organizationId, $searchTerm, $ticketingActive, $ticketState, $sortDirection, $role, $allowAgentsToViewAllChats);
        // $rowCount = $contact->contactsWithChatsCount($this->organizationId, $searchTerm, $ticketingActive, $ticketState, $sortDirection, $role, $allowAgentsToViewAllChats);

        
    
        $contact = new Contact;
        $contactsQuery = $contact->contactsWithChatsOptimized(
            $this->organizationId,
            $searchTerm,
            $ticketingActive,
            $ticketState,
            $sortDirection,
            $role,
            $allowAgentsToViewAllChats
        );

        $contacts = $contactsQuery;
        $rowCount = $contacts->total();
        // dd($contacts->first());
        
    
        $pusherSettings = Setting::whereIn('key', [
            'pusher_app_id',
            'pusher_app_key',
            'pusher_app_secret',
            'pusher_app_cluster',
        ])->pluck('value', 'key')->toArray();

        //   $perPage = 10; // Number of items per page
        //    $totalContacts = count($contacts); // Total number of contacts
        $messageTemplates = Template::where('organization_id', $this->organizationId)
            ->where('deleted_at', null)
            ->where('status', 'APPROVED')
            ->get();
        // $end = microtime(true);
        // if($end-$start > 1){
        // 	logger('From ChatService -  getChatList - '.$end-$start);
        // }
        if ($uuid !== null) {
            $contact = Contact::with(['lastChat', 'lastInboundChat', 'notes', 'contactGroups'])
                ->where('uuid', $uuid)
                ->first();
				/**
				 * @var Contact $contact
				 */
				$contact->encryptPhoneNumber(Contact::contactPhoneNumberShouldEncrypted());
            
            $ticket = ChatTicket::with('user')
                ->where('contact_id', $contact->id)
                ->first();
            $initialMessages = $this->getChatMessages($contact->id);
            // Mark messages as read
            DB::table('chats')->where('contact_id', $contact->id)
                ->where('type', 'inbound')
                ->whereNull('deleted_at')
                ->where('is_read', 0)
                ->update(['is_read' => 1]);
            
            if (request()->expectsJson()) {
            
                return response()->json([
                    'result' => ContactResource::collection($contacts)->response()->getData(),
                ], 200);
            } else {
                $settings = json_decode($config->metadata);

                //To ensure the unread message counter is updated
                $unreadMessages = DB::table('chats')->where('organization_id', $this->organizationId)
                    ->where('type', 'inbound')
                    ->where('deleted_at', null)
                    ->where('is_read', 0)
                    ->count();

                return Inertia::render('User/Chat/Index', [
                    'title' => 'Chats',
                    'rows' => ContactResource::collection($contacts),
                    'simpleForm' => CustomHelper::isModuleEnabled('AI Assistant') && optional(optional($settings)->ai)->ai_chat_form_active ? false : true,
                    'rowCount' => $rowCount,
                    'filters' => request()->all(),
                    'pusherSettings' => $pusherSettings,
                    'organizationId' => $this->organizationId,
                    'state' => app()->environment(),
                    'demoNumber' => env('DEMO_NUMBER'),
                    'settings' => $config,
                    'templates' => $messageTemplates,
                    'status' => $request->status ?? 'all',
                    'chatThread' => $initialMessages['messages'],
                    'hasMoreMessages' => $initialMessages['hasMoreMessages'],
                    'nextPage' => $initialMessages['nextPage'],
                    'contact' => $contact,
                    'fields' => ContactField::where('organization_id', $this->organizationId)->where('deleted_at', null)->get(),
                    'locationSettings' => $this->getLocationSettings(),
                    'ticket' => $ticket,
                //    'agents' => $agents,
                    'addon' => $aimodule,
                    'chat_sort_direction' => $sortDirection,
                    'unreadMessages' => $unreadMessages,
                    'isChatLimitReached' => SubscriptionService::isSubscriptionFeatureLimitReached($this->organizationId, 'message_limit')
                ]);
            }
        }
        
        if (request()->expectsJson()) {
            return response()->json([
                'result' => ContactResource::collection($contacts)->response()->getData(),
            ], 200);
        } else {
            $settings = json_decode($config->metadata);
            
            return Inertia::render('User/Chat/Index', [
                'title' => 'Chats',
                'rows' => ContactResource::collection($contacts),
                'simpleForm' => !CustomHelper::isModuleEnabled('AI Assistant') || empty($settings->ai->ai_chat_form_active),
                'rowCount' => $rowCount,
                'filters' => request()->all(),
                'pusherSettings' => $pusherSettings,
                'organizationId' => $this->organizationId,
                'state' => app()->environment(),
                'settings' => $config,
                'templates' => $messageTemplates,
                'status' => $request->status ?? 'all',
             //   'agents' => $agents,
                'addon' => $aimodule,
                'ticket' => array(),
                'chat_sort_direction' => $sortDirection,
                'isChatLimitReached' => SubscriptionService::isSubscriptionFeatureLimitReached($this->organizationId, 'message_limit')
            ]);
        }
    }

    public function handleTicketAssignment($contactId)
    {
        //	$start = microtime(true);
        $organizationId = $this->organizationId;
        $settings = Organization::where('id', $this->organizationId)->first();
        $settings = json_decode($settings->metadata);

        // Check if ticket functionality is active
        if (isset($settings->tickets) && $settings->tickets->active === true) {
            $autoassignment = $settings->tickets->auto_assignment;
            $reassignOnReopen = $settings->tickets->reassign_reopened_chats;

            // Check if a ticket already exists for the contact
            $ticket = ChatTicket::where('contact_id', $contactId)->first();

            DB::transaction(function () use ($reassignOnReopen, $autoassignment, $ticket, $contactId, $organizationId) {
                if (!$ticket) {
                    $now = DateTimeHelper::convertToOrganizationTimezone(now(), $this->organizationId);
                    // Create a new ticket if it doesn't exist
                    $ticket = new ChatTicket;
                    $ticket->contact_id = $contactId;
                    $ticket->status = 'open';
                    $ticket->created_at =  $now;
                    $ticket->updated_at =  $now;

                    // Perform auto-assignment if enabled
                    if ($autoassignment) {
                        // Find an agent with the least number of assigned tickets
                        $agent = Team::where('organization_id', $organizationId)
                            ->withCount('tickets')
                            ->whereNull('deleted_at')
                            ->orderBy('tickets_count')->first();

                        // Assign the ticket to the agent with the least number of assigned tickets
                        $ticket->assigned_to = $agent->user_id;
                    } else {
                        $ticket->assigned_to = null;
                    }

                    $ticket->save();

                    $ticketId = ChatTicketLog::insertGetId([
                        'contact_id' => $contactId,
                        'description' => 'Conversation was opened',
                        'created_at' => $now
                    ]);

                    ChatLog::insert([
                        'contact_id' => $contactId,
                        'entity_type' => 'ticket',
                        'entity_id' => $ticketId,
                        'created_at' => $now
                    ]);
                } else {
                    // Reopen the ticket if it's closed and reassignment on reopen is enabled
                    if ($ticket->status === 'closed') {
                        if ($reassignOnReopen) {
                            if ($autoassignment) {
                                $agent = Team::where('organization_id', $organizationId)
                                    ->withCount('tickets')
                                    ->whereNull('deleted_at')
                                    ->orderBy('tickets_count')
                                    ->first();

                                $ticket->assigned_to = $agent->user_id;
                            } else {
                                $ticket->assigned_to = null;
                            }
                        }

                        $ticket->status = 'open';
                        $ticket->save();

                        $ticketId = ChatTicketLog::insertGetId([
                            'contact_id' => $contactId,
                            'description' => 'Conversation was moved from closed to open',
                            'created_at' =>  DateTimeHelper::convertToOrganizationTimezone(now(), $this->organizationId)
                        ]);
    
                        ChatLog::insert([
                            'contact_id' => $contactId,
                            'entity_type' => 'ticket',
                            'entity_id' => $ticketId,
                            'created_at' =>  DateTimeHelper::convertToOrganizationTimezone(now(), $this->organizationId)
                        ]);
                    }
                }
            });
        }
        
        
    }

    public function sendMessage(object $request)
    {
        // $time = microtime(true);
        if ($request->type === 'text') {
            return $this->whatsappService->sendMessage($request->uuid, $request->message, auth()->user()->id);
        } else {
            $storage = Setting::where('key', 'storage_system')->first()->value;
            $fileName = $request->file('file')->getClientOriginalName();
            $fileContent = $request->file('file');

            if ($storage === 'local') {
                $location = 'local';
                $file = Storage::disk('local')->put('public', $fileContent);
                $mediaFilePath = $file;
                $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
            } elseif ($storage === 'aws') {
                $location = 'amazon';
                $file = $request->file('file');
                $filePath = 'uploads/media/received/'  . $this->organizationId . '/' . $fileName;
                $uploadedFile = $file->store('uploads/media/sent/' . $this->organizationId, 's3');
                $mediaFilePath = Storage::disk('s3')->url($uploadedFile);
                $mediaUrl = $mediaFilePath;
            }
    
            $this->whatsappService->sendMedia($request->uuid, $request->type, $fileName, $mediaFilePath, $mediaUrl, $location);
        }
        // $end = microtime(true);
        // if($end-$time > 1){
        // 	logger('From ChatService -  sendMessage - '.$end-$time);
        // }
    }

    public function sendTemplateMessage(object $request, $uuid)
    {
        $template = Template::where('uuid', $request->template)->first();
        $contact = Contact::where('uuid', $uuid)->first();
        $mediaId = null;

        if (in_array($request->header['format'], ['IMAGE', 'DOCUMENT', 'VIDEO'])) {
            $header = $request->header;
            
            if ($request->header['parameters']) {
                $metadata['header']['format'] = $header['format'];
                $metadata['header']['parameters'] = [];
        
                foreach ($request->header['parameters'] as $key => $parameter) {
                    if ($parameter['selection'] === 'upload') {
                        $storage = Setting::where('key', 'storage_system')->first()->value;
                        $fileName = $parameter['value']->getClientOriginalName();
                        $fileContent = $parameter['value'];

                        if ($storage === 'local') {
                            $file = Storage::disk('local')->put('public', $fileContent);
                            $mediaFilePath = $file;
            
                            $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
                        } elseif ($storage === 'aws') {
                            $file = $parameter['value'];
                            $uploadedFile = $file->store('uploads/media/sent/' . $this->organizationId, 's3');
                            $mediaFilePath = Storage::disk('s3')->url($uploadedFile);
            
                            $mediaUrl = $mediaFilePath;
                        }

                        $contentType = $this->getContentTypeFromUrl($mediaUrl);
                        $mediaSize = $this->getMediaSizeInBytesFromUrl($mediaUrl);

                        //save media
                        $chatMedia = new ChatMedia;
                        $chatMedia->name = $fileName;
                        $chatMedia->location = $storage == 'aws' ? 'amazon' : 'local';
                        $chatMedia->path = $mediaUrl;
                        $chatMedia->type = $contentType;
                        $chatMedia->size = $mediaSize;
                        $chatMedia->created_at =  DateTimeHelper::convertToOrganizationTimezone(now(), $this->organizationId);
                        $chatMedia->save();

                        $mediaId = $chatMedia->id;
                    } else {
                        $mediaUrl = $parameter['value'];
                    }
        
                    $metadata['header']['parameters'][] = [
                        'type' => $parameter['type'],
                        'selection' => $parameter['selection'],
                        'value' => $mediaUrl,
                    ];
                }
            }
        } else {
            $metadata['header'] = $request->header;
        }

        $metadata['body'] = $request->body;
        $metadata['footer'] = $request->footer;
        $metadata['buttons'] = $request->buttons;
        $metadata['media'] = $mediaId;

        //Build Template to send
        $template = $this->buildTemplate($template->name, $template->language, json_decode(json_encode($metadata)), $contact);
        
        return $this->whatsappService->sendTemplateMessage($contact->uuid, $template, auth()->user()->id, null, $mediaId);
    }

    public function clearMessage($uuid)
    {
        Chat::where('uuid', $uuid)
            ->update([
                'deleted_by' => auth()->user()->id,
                'deleted_at' =>  DateTimeHelper::convertToOrganizationTimezone(now(), $this->organizationId)
            ]);
    }

    public function clearContactChat($uuid)
    {
        $contact = Contact::with('lastChat')->where('uuid', $uuid)->firstOrFail();
        Chat::where('contact_id', $contact->id)->update([
            'deleted_by' => auth()->user()->id,
            'deleted_at' =>  DateTimeHelper::convertToOrganizationTimezone(now(), $this->organizationId)
        ]);

        ChatLog::where('contact_id', $contact->id)->where('entity_type', 'chat')->update([
            'deleted_by' => auth()->user()->id,
            'deleted_at' =>  DateTimeHelper::convertToOrganizationTimezone(now(), $this->organizationId)
        ]);

        //    $chat = Chat::with('contact','media')->where('id', $contact->lastChat->id)->first();

        //event(new NewChatEvent($chat, $contact->organization_id));
    }

    private function getContentTypeFromUrl($url)
    {
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

    private function getMediaSizeInBytesFromUrl($url)
    {
        $url = ltrim($url, '/');
        $imageContent = file_get_contents($url);
    
        if ($imageContent !== false) {
            return strlen($imageContent);
        }
    
        return null;
    }

    private function getLocationSettings()
    {
        // Retrieve the settings for the current organization
        $settings = Organization::where('id', $this->organizationId)->first();

        if ($settings) {
            // Decode the JSON metadata column into an associative array
            $metadata = json_decode($settings->metadata, true);

            if (isset($metadata['contacts'])) {
                // If the 'contacts' key exists, retrieve the 'location' value
                $location = $metadata['contacts']['location'];

                // Now, you have the location value available
                return $location;
            } else {
                return null;
            }
        } else {
            return null;
        }
    }

    public function getChatMessages($contactId, $page = 1, $perPage = 50)
    {
        $chatLogs = ChatLog::where('contact_id', $contactId)
        ->where('deleted_at', null)
        ->orderBy('created_at', 'desc')
        ->paginate($perPage, ['*'], 'page', $page)
        ;
        $chats = [];
        foreach ($chatLogs as $chatLog) {
            $chats[] = array([
                'type' => $chatLog->entity_type,
                'value' => $chatLog->relatedEntities
            ]);
        }
        return [
            'messages' => array_reverse($chats),
            'hasMoreMessages' => $chatLogs->hasMorePages(),
            'nextPage' => $chatLogs->currentPage() + 1
        ];
    }
    private function ensureChatTicketsExist()
    {
        // ✅ استخدام Cache لتجنب تشغيل هذا في كل طلب
        $cacheKey = "chat_tickets_created_{$this->organizationId}";
        
        // تشغيل مرة واحدة كل ساعة
        if (Cache::has($cacheKey)) {
            return;
        }

        try {
            // ✅ إيجاد جهات الاتصال بدون تذاكر (استعلام واحد)
            $contactsWithoutTickets = DB::table('contacts')
                ->select('contacts.id')
                ->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id')
                ->where('contacts.organization_id', $this->organizationId)
                ->whereNull('contacts.deleted_at')
                ->whereNotNull('contacts.latest_chat_created_at')
                ->whereNull('chat_tickets.id')
                ->pluck('contacts.id');

            if ($contactsWithoutTickets->isEmpty()) {
                Cache::put($cacheKey, true, 3600); // ساعة واحدة
                return;
            }

            //  إنشاء دفعي - استعلام واحد فقط!
            $now =  DateTimeHelper::convertToOrganizationTimezone(now(), $this->organizationId);
            $ticketsData = $contactsWithoutTickets->map(function ($contactId) use ($now) {
                return [
                    'contact_id' => $contactId,
                    'assigned_to' => null,
                    'status' => 'open',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->toArray();

            //  إدراج دفعي - Query واحد بدلاً من 1000!
            if (!empty($ticketsData)) {
                // تقسيم إلى chunks لتجنب مشاكل memory
                collect($ticketsData)->chunk(500)->each(function ($chunk) {
                    ChatTicket::insert($chunk->toArray());
                });
            }

            // وضع علامة أن العملية تمت
            Cache::put($cacheKey, true, 3600); // ساعة واحدة

        } catch (\Exception $e) {
            Log::error('Error creating chat tickets: ' . $e->getMessage());
        }
    }
    public function blockContact(Organization $organization, Contact $contact)
    {
        $metadata = json_decode($organization->metadata);
        //  $organizationId = $organization->id;
        //	dd($contact);
        $config = json_decode(Organization::where('id', $this->organizationId)->first()->metadata?:'{}', true);
    
        if (empty($metadata) || empty($metadata->whatsapp->access_token) || !isset($config['whatsapp'])) {
            return response()->json([
                'success' => false,
                'message' => 'Access token not found',
            ]);
        }
         
        
        //		  $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        //          $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        //     $whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);
        
    

        $client = new Client();
        try {
            $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/block_users";
            $requestData = [
                'messaging_product' => 'whatsapp',
                'block_users' => [
                    [
                        'user' => $contact->phone
                    ]
                ]
            ];
    
            $requestOptions = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $metadata->whatsapp->access_token,
                ],
                'json' => $requestData
            ];
    
            $response = $client->request('POST', $url, $requestOptions);
            $result = json_decode($response->getBody()->getContents(), true);
            
            if (isset($result['errors']['code']) && $result['errors']['code'] ==139100) {
    
                logger('fail to block contact'.$result['errors']['message']);
                return [
                    'status'=>false ,
                    'message'=>__('Cannot block contact. They must have messaged you within the last 24 hours.')
                ];
            }
            if (isset($result['errors']['code'])) {
    
                logger('fail to block contact'.$result['errors']['message']);
                return [
                    'status'=>false ,
                    'message'=>__('Cannot block contact.')
                ];
            }
        
            if (!empty($result['block_users']['added_users'])) {
                $contact->markAsBlocked();
                return [
                    'status' => true,
                    'message' => __('Contact blocked successfully'),
                ];
            }
        
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            
            // للحصول على رسالة الخطأ الكاملة
            $response = $e->getResponse();
            $errorBody = $response->getBody()->getContents();
            $errorData = json_decode($errorBody, true);
            return [
                      'status'=>false ,
                      'message'=>__('Cannot block contact.')
                  ];
          
        }
    }
    public function unBlockContact(Organization $organization, Contact $contact)
    {
        $metadata = json_decode($organization->metadata);
        //  $organizationId = $organization->id;
        //	dd($contact);
        $config = json_decode(Organization::where('id', $this->organizationId)->first()->metadata?:'{}', true);
    
        if (empty($metadata) || empty($metadata->whatsapp->access_token) || !isset($config['whatsapp'])) {
            return response()->json([
                'success' => false,
                'message' => 'Access token not found',
            ]);
        }
         
        
        //		  $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        //          $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        //     $whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);
        
    

        $client = new Client();
        try {
            $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/block_users";
            $requestData = [
                'messaging_product' => 'whatsapp',
                'block_users' => [
                    [
                        'user' => $contact->phone
                    ]
                ]
            ];
    
            $requestOptions = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $metadata->whatsapp->access_token,
                ],
                'json' => $requestData
            ];
            $response = $client->request('DELETE', $url, $requestOptions);
            $result = json_decode($response->getBody()->getContents(), true);
            if (isset($result['block_users']['removed_users']) && count($result['block_users']['removed_users'])) {
                $contact->markAsUnBlocked();
                return [
                    'status' => true,
                    'message' => __('Contact unblocked successfully'),
                ];
            }
            return [
                        'status'=>false ,
                        'message'=>__('Cannot unblock contact')
                    ];
        
        
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            
            // للحصول على رسالة الخطأ الكاملة
            $response = $e->getResponse();
            $errorBody = $response->getBody()->getContents();
            $errorData = json_decode($errorBody, true);
            return [
                      'status'=>false ,
                      'message'=>__('Cannot unblock contact')
                  ];
          
        }
    }
    

}
