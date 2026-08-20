<?php

namespace App\Services;

use App\Events\ContactChatDeletedEvent;
use App\Helpers\CustomHelper;
use App\Helpers\DateTimeHelper;
use App\Helpers\MessagingWindowHelper;
use App\Http\Resources\ContactListResource;
use App\Jobs\SendMediaJob;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatMedia;
use App\Models\ChatNote;
use App\Models\ChatTicket;
use App\Models\ChatTicketLog;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\Template;
use App\Models\User;
use App\Services\Chat\ChatReadService;
use App\Services\ImageCompressionService;
use App\Services\SubscriptionService;
use App\Services\WhatsappService;
use App\Support\OrganizationRole;
use App\Traits\TemplateTrait;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use App\Services\ActivityLogger;

class ChatService
{
    use TemplateTrait;

    private WhatsappService $whatsappService;
    private $organizationId;

    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
       
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

    private function canAccessConversation(User $user, Contact $contact): bool
    {
        if ((int) $contact->organization_id !== (int) $this->organizationId) {
            return false;
        }

        $role = $user->getRoleNameForOrganization($this->organizationId);
        if ($role === '') {
            $role = OrganizationRole::OWNER;
        }

        if ($role !== 'agent') {
            return true;
        }

        $organization = Organization::find($this->organizationId);
        if (!$organization || !$organization->getTicketingActive()) {
            return true;
        }

        if ($organization->getAllowAgentsToViewAllChats()) {
            return true;
        }

        $ticket = ChatTicket::where('contact_id', $contact->id)
            ->where('is_latest', true)
            ->first();

        return $ticket && (int) $ticket->assigned_to === (int) $user->id;
    }

    private function assertConversationAccess(Contact $contact): void
    {
        $user = auth()->user();
        if (!$user || !$this->canAccessConversation($user, $contact)) {
            abort(403, __('You are not allowed to access this conversation.'));
        }
    }

    private function findContactByUuidInOrganization(string $uuid): ?Contact
    {
        return Contact::where('uuid', $uuid)
            ->where('organization_id', $this->organizationId)
            ->first();
    }

    private function contactNotFoundResponse()
    {
        if (request()->expectsJson()) {
            return response()->json(['message' => __('Contact not found')], 404);
        }

        return redirect('/chats')->with('status', [
            'type' => 'error',
            'message' => __('Contact not found'),
        ]);
    }

    private function markInboundChatsAsRead(int $contactId): void
    {
        ChatReadService::markInboundAsRead($contactId, (int) $this->organizationId);
    }

    /**
     * Mark a contact's inbound messages as read from its uuid, enforcing the
     * same access rules as opening the conversation. Used by the lightweight
     * POST /chats/{uuid}/read endpoint so an open thread reliably clears its
     * unread state even when new messages arrive over websockets.
     */
    public function markContactAsReadByUuid(string $uuid): bool
    {
        $contact = $this->findContactByUuidInOrganization($uuid);
        if ($contact === null) {
            return false;
        }

        $this->assertConversationAccess($contact);
        $this->markInboundChatsAsRead($contact->id);
        $this->markTicketAssignmentSeen($contact->id);

        return true;
    }

    /**
     * Clear the "new assignment" indicator for a contact's latest ticket once
     * the current agent has opened the conversation.
     */
    private function markTicketAssignmentSeen(int $contactId): void
    {
        ChatTicket::where('contact_id', $contactId)
            ->where('is_latest', true)
            ->where('assigned_seen', 0)
            ->update(['assigned_seen' => 1]);
    }

    public function getChatList($request, $uuid = null, $searchTerm = null)
    {
		$partialHeader = request()->header('X-Inertia-Partial-Data', '');
		$requestedProps = $partialHeader !== '' ? array_map('trim', explode(',', $partialHeader)) : [];
		// تخطي استعلام rows فقط عندما الطلب جزئي ولا يطلب العميل 'rows' (مثلاً عودة من محادثة إلى القائمة)
		$skipRowsQuery = $partialHeader !== '' && !in_array('rows', $requestedProps);

        //	$uuid = 'b27a5a63-05d4-4e2f-911c-4da76044328c';
        // $role = auth()->user()->teams[0]->role;
		$currentUser = auth()->user();
		/**
		 * @var User $currentUser
		 */
		$role = $currentUser->getRoleNameForOrganization($this->organizationId);
		if ($role === '') {
			$role = OrganizationRole::OWNER;
		}

        $config = Organization::find($this->organizationId);
        $ticketState = $request->status == null ? 'all' : $request->status;
		$sortDirection = 'desc';
		if($request->is('api/v1/*')){
			$sortDirection = $request->sort_direction ?? 'desc';
		}else{
			$sortDirection = $request->session()->get('chat_sort_direction') ?? 'desc';
		}
 
        $ticketingActive = false;
	
        $aimodule = CustomHelper::isModuleEnabled('AI Assistant',$this->organizationId);
        //Check if tickets module has been enabled
		$allowAgentsToViewAllChats =true;
		if($config->getTicketingActive()){
			$ticketingActive = true;
			if (!in_array($ticketState, ['open', 'closed', 'unassigned'], true)) {
				$this->ensureChatTicketsExist();
			}
			$allowAgentsToViewAllChats = $config->getAllowAgentsToViewAllChats();
		}

        $contactQuery = new Contact;
		$rowCount = -1;
		$pusherSettings = [];
		$contacts = [];
		$hasMoreContacts = false;
		$nextContactsPage = null;
	
		if(!$skipRowsQuery){
			$contactCategoriesEnabled = SubscriptionService::isSubscriptionFeatureEnabled((string) $this->organizationId, 'contact_categories_enabled');
			$categoryUuid = $request->query('category');

			$contactPage = $request->input('contact_page', 1);
			$contactsPaginated = $contactQuery->contactsWithChatsOptimized(
				$this->organizationId,
				$searchTerm,
				$ticketingActive,
				$ticketState,
				$sortDirection,
				$role,
				$allowAgentsToViewAllChats,
				$contactCategoriesEnabled,
				50,
				$contactPage,
				$categoryUuid,
			);

			$contacts = $contactsPaginated;
			$rowCount = $contactsPaginated->total();
			$hasMoreContacts = $contactsPaginated->hasMorePages();
			$nextContactsPage = $hasMoreContacts ? (int)$contactPage + 1 : null;
			$pusherSettings = Setting::whereIn('key', [
				'pusher_app_key',
				'pusher_app_cluster',
			])->pluck('value', 'key')->toArray();
			
		}

		$rowsForResponse = is_array($contacts) ? $contacts : ContactListResource::collection($contacts);
        // $rowCount = $contacts->total();

        // تجنّب N+1: ربط الـ organization مرة واحدة لاستخدامه في ContactResource
     //   $contacts->getCollection()->each(fn ($c) => $c->setRelation('organization', $config));


        //   $perPage = 10; // Number of items per page
        //    $totalContacts = count($contacts); // Total number of contacts
        $messageTemplates = Template::where('organization_id', $this->organizationId)
            ->where('deleted_at', null)
            ->where('status', 'APPROVED')
            ->select(['uuid', 'name', 'language'])
            ->get();
   
        if ($uuid !== null) {
			// $start = microtime(true);
			// أعمدة الـ contact المستخدمة فعلياً: contactPayloadForChatView + encryptPhoneNumber + id للـ ticket و getChatMessages
            $contactCategoriesEnabled = SubscriptionService::isSubscriptionFeatureEnabled((string) $this->organizationId, 'contact_categories_enabled');
            $contactWith = ['contactGroups:id,name', 'organization:id,metadata'];
            if ($contactCategoriesEnabled) {
                $contactWith[] = 'contactCategories:id,name,uuid,background_color,text_color';
            }
            $contact = Contact::query()
                ->select([
                    'id',
                    'uuid',
                    'first_name',
                    'last_name',
                    'phone',
                    'email',
                    'organization_id',
                    'is_blocked',
                    'is_favorite',
                    'metadata',
                    'address',
                    'avatar',
                    'last_inbound_chat_created_at',
                    'deleted_at',
                ])
                ->with($contactWith)
                ->where('uuid', $uuid)
                ->where('organization_id', $this->organizationId)
                ->first();

			if ($contact === null) {
				return $this->contactNotFoundResponse();
			}

			try {
				$this->assertConversationAccess($contact);
			} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
				if ($e->getStatusCode() === 403) {
					if (request()->expectsJson()) {
						return response()->json(['message' => $e->getMessage()], 403);
					}

					return redirect('/chats')->with('status', [
						'type' => 'error',
						'message' => $e->getMessage(),
					]);
				}

				throw $e;
			}

			$this->markInboundChatsAsRead($contact->id);
			$this->markTicketAssignmentSeen($contact->id);

				/**
				 * @var Contact $contact
				 */
				// $end = microtime(true);
		    	// $time = $end - $start;
	
		
				$contact->encryptPhoneNumber(Contact::contactPhoneNumberShouldEncrypted());
            $ticket = ChatTicket::with('user')
                ->where('contact_id', $contact->id)
                ->first();
            $initialMessages = $this->getChatMessages($contact->id);
            // Mark messages as read
         
            if (request()->expectsJson()) {
                $result = is_array($rowsForResponse) ? $rowsForResponse : $rowsForResponse->resolve();
                return response()->json([
                    'result' => $result,
                ], 200);
            } else {
                $settings = json_decode($config->metadata);
                //To ensure the unread message counter is updated
                // $unreadMessages = DB::table('chats')->where('organization_id', $this->organizationId)
                //     ->where('type', 'inbound')
                //     ->where('deleted_at', null)
                //     ->where('is_read', 0)
                //     ->count();
                return Inertia::render('User/Chat/Index', [
                    'title' => 'Chats',
                    'rows' => $rowsForResponse,
                    'simpleForm' => CustomHelper::isModuleEnabled('AI Assistant') && optional(optional($settings)->ai)->ai_chat_form_active ? false : true,
                    'rowCount' => $rowCount,
                    'filters' => request()->all(),
                    'pusherSettings' => $pusherSettings,
                    'organizationId' => $this->organizationId,
                    'settings' => $config,
                   'templates' => $messageTemplates,
                    'status' => $request->status ?? 'all',
                    'nextPage' => $initialMessages['nextPage'],
                    'contact' => $uuid ? self::contactPayloadForChatView($contact) : null,
                    'chatThread' => $uuid ? $initialMessages['messages'] : [],
                    'fields' => ContactField::where('organization_id', $this->organizationId)->where('deleted_at', null)->get(),
                    'locationSettings' => $this->getLocationSettings(),
                    'ticket' => $ticket,
                    'addon' => $aimodule,
                    'chat_sort_direction' => $sortDirection,
                    'hasMoreMessages' => $initialMessages['hasMoreMessages'],
					'user' => auth()->user()->only(['id', 'first_name', 'last_name']),
					'timezone'=>DateTimeHelper::getCurrentTimeZone($this->organizationId),
                    'isChatLimitReached' => SubscriptionService::isSubscriptionFeatureLimitReached($this->organizationId, 'message_limit'),
                    'contactCategoriesEnabled' => SubscriptionService::isSubscriptionFeatureEnabled((string) $this->organizationId, 'contact_categories_enabled'),
                    'hasMoreContacts' => $hasMoreContacts,
                    'nextContactsPage' => $nextContactsPage,
                ]);
            }
        }
        
        if (request()->expectsJson()) {
            $result = is_array($rowsForResponse) ? $rowsForResponse : $rowsForResponse->resolve();
            return response()->json([
                'result' => $result,
            ], 200);
        } else {
            $settings = json_decode($config->metadata);
            
            return Inertia::render('User/Chat/Index', [
                'title' => 'Chats',
                'rows' => $rowsForResponse,
                'simpleForm' => !CustomHelper::isModuleEnabled('AI Assistant') || empty($settings->ai->ai_chat_form_active),
                'rowCount' => $rowCount,
                'filters' => request()->all(),
                'pusherSettings' => $pusherSettings,
                'organizationId' => $this->organizationId,
            //    'state' => app()->environment(),
                'settings' => $config,
               'templates' => $messageTemplates,
                'status' => $request->status ?? 'all',
             //   'agents' => $agents,
                'addon' => $aimodule,
                'ticket' => array(),
                'chat_sort_direction' => $sortDirection,
				'user' => auth()->user()->only(['id', 'first_name', 'last_name']),
				'timezone'=>DateTimeHelper::getCurrentTimeZone($this->organizationId),
                'isChatLimitReached' => SubscriptionService::isSubscriptionFeatureLimitReached($this->organizationId, 'message_limit'),
                // لإرجاع عرض القائمة عند الطلب الجزئي (مثلاً الرجوع من محادثة) دون إعادة تحميل rows
                'contact' => null,
                'chatThread' => [],
                'hasMoreMessages' => false,
                'nextPage' => 1,
                'contactCategoriesEnabled' => SubscriptionService::isSubscriptionFeatureEnabled((string) $this->organizationId, 'contact_categories_enabled'),
                'hasMoreContacts' => $hasMoreContacts,
                'nextContactsPage' => $nextContactsPage,
            ]);
        }
    }

    public function getContactsPage($request)
    {
        $currentUser = auth()->user();
        $role = $currentUser->getRoleNameForOrganization($this->organizationId);
        if ($role === '') {
            $role = OrganizationRole::OWNER;
        }
        $config = Organization::find($this->organizationId);
        $ticketingActive = $config->getTicketingActive();
        $allowAgentsToViewAllChats = $ticketingActive ? $config->getAllowAgentsToViewAllChats() : true;
        $sortDirection = $request->session()->get('chat_sort_direction') ?? 'desc';
        $searchTerm = $request->query('search');
        $ticketState = $request->status == null ? 'all' : $request->status;
        $categoryUuid = $request->query('category');
        $contactPage = $request->input('contact_page', 1);
        $contactCategoriesEnabled = SubscriptionService::isSubscriptionFeatureEnabled((string) $this->organizationId, 'contact_categories_enabled');

        $contactsPaginated = (new Contact)->contactsWithChatsOptimized(
            $this->organizationId,
            $searchTerm,
            $ticketingActive,
            $ticketState,
            $sortDirection,
            $role,
            $allowAgentsToViewAllChats,
            $contactCategoriesEnabled,
            50,
            $contactPage,
            $categoryUuid,
        );

        return response()->json([
            'data' => ContactListResource::collection($contactsPaginated->items())->resolve(),
            'hasMoreContacts' => $contactsPaginated->hasMorePages(),
            'nextContactsPage' => $contactsPaginated->hasMorePages() ? (int)$contactPage + 1 : null,
            'rowCount' => $contactsPaginated->total(),
        ]);
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
                    $now = now();
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
                            'created_at' =>  now()
                        ]);
    
                        ChatLog::insert([
                            'contact_id' => $contactId,
                            'entity_type' => 'ticket',
                            'entity_id' => $ticketId,
                            'created_at' => now()
                        ]);
                    }
                }
            });
        }
        
        
    }

    /**
     * إرسال «طلب موقع» إلى العميل — رسالة تفاعلية تُظهر زرّ «إرسال الموقع».
     *
     * ليست قالباً: قوالب Meta لا تحوي هذا النوع، فهي تخضع لنافذة الأربع
     * وعشرين ساعة كأي رسالة عادية، ولذلك تمرّ بنفس حراسات sendMessage.
     */
    public function requestLocation(string $contactUuid, string $bodyText, $userId = null)
    {
        $this->initializeWhatsappService();

        $contact = $this->findContactByUuidInOrganization($contactUuid);
        if (!$contact) {
            return response()->json([
                'success' => false,
                'message' => __('Contact not found'),
            ], 404);
        }

        $this->assertConversationAccess($contact);

        if (!MessagingWindowHelper::isMessagingWindowOpen($contact)) {
            return MessagingWindowHelper::closedWindowJsonResponse();
        }

        if (trim($bodyText) === '') {
            return response()->json([
                'success' => false,
                'message' => __('Message cannot be empty.'),
            ], 422);
        }

        ActivityLogger::log(
            ActivityLogger::MESSAGE_SENT,
            trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $contact->phone,
            'contact',
            $contact->id,
            ['kind' => 'location_request'],
            (int) $this->organizationId
        );

        return $this->whatsappService->sendMessage(
            $contact->uuid,
            $bodyText,
            $userId,
            WhatsappService::TYPE_LOCATION_REQUEST
        );
    }

    /**
     * إرسال موقع النشاط التجاري إلى العميل — عكس requestLocation.
     *
     * @param  array{latitude: mixed, longitude: mixed, name?: ?string, address?: ?string}  $location
     */
    public function sendLocation(string $contactUuid, array $location, $userId = null)
    {
        $this->initializeWhatsappService();

        $contact = $this->findContactByUuidInOrganization($contactUuid);
        if (!$contact) {
            return response()->json([
                'success' => false,
                'message' => __('Contact not found'),
            ], 404);
        }

        $this->assertConversationAccess($contact);

        if (!MessagingWindowHelper::isMessagingWindowOpen($contact)) {
            return MessagingWindowHelper::closedWindowJsonResponse();
        }

        if (!WhatsappService::isUsableLocation($location)) {
            return response()->json([
                'success' => false,
                'message' => __('A valid location is required.'),
            ], 422);
        }

        ActivityLogger::log(
            ActivityLogger::MESSAGE_SENT,
            trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $contact->phone,
            'contact',
            $contact->id,
            ['kind' => 'location'],
            (int) $this->organizationId
        );

        return $this->whatsappService->sendLocation($contact->uuid, $location, $userId);
    }

    /**
     * موقع النشاط التجاري المحفوظ في إعدادات المنشأة، أو null إن لم يُضبط بعد.
     *
     * العنوان مخزّن JSON في عمود address، والإحداثيات حقلان داخله أُضيفا مع
     * هذه الميزة — فالمنشآت القديمة تُرجع null حتى يحدّد صاحبها موقعه.
     *
     * @return array{latitude: float, longitude: float, name: string, address: string}|null
     */
    public function getOrganizationLocation(): ?array
    {
        $organization = Organization::find($this->organizationId);
        if (!$organization) {
            return null;
        }

        return self::resolveOrganizationLocation($organization);
    }

    /**
     * @return array{latitude: float, longitude: float, name: string, address: string}|null
     */
    public static function resolveOrganizationLocation(Organization $organization): ?array
    {
        $address = json_decode((string) $organization->address, true);
        if (!is_array($address)) {
            return null;
        }

        $location = [
            'latitude' => $address['latitude'] ?? null,
            'longitude' => $address['longitude'] ?? null,
        ];

        if (!WhatsappService::isUsableLocation($location)) {
            return null;
        }

        // العنوان النصّي يُبنى من الحقول المضبوطة فقط: المنشآت لا تملأ كلها
        // كل حقل، والفواصل المتتالية حول الفراغات تظهر للعميل في البطاقة.
        $parts = array_filter([
            $address['street'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['zip'] ?? null,
            $address['country'] ?? null,
        ], fn ($part) => trim((string) $part) !== '');

        return [
            'latitude' => (float) $location['latitude'],
            'longitude' => (float) $location['longitude'],
            'name' => (string) $organization->name,
            'address' => implode('، ', array_map(fn ($part) => trim((string) $part), $parts)),
        ];
    }

    public function sendMessage(object $request)
    {
		$this->initializeWhatsappService();
        $contact = $this->findContactByUuidInOrganization((string) $request->uuid);
        if (!$contact) {
            return response()->json([
                'success' => false,
                'message' => __('Contact not found'),
            ], 404);
        }

        $this->assertConversationAccess($contact);

        if (!MessagingWindowHelper::isMessagingWindowOpen($contact)) {
            return MessagingWindowHelper::closedWindowJsonResponse();
        }

        ActivityLogger::log(
            $request->file('file') ? ActivityLogger::MEDIA_SENT : ActivityLogger::MESSAGE_SENT,
            trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $contact->phone,
            'contact',
            $contact->id,
            [],
            (int) $this->organizationId
        );

        // مرفقات متعدّدة في طلب واحد (اللصق والسحب والإفلات). كل ملف كان
        // يستهلك رحلة HTTP كاملة، فثلاثة ملفات ثلاث رحلات متعاقبة. الرحلة
        // الواحدة تضمن ترتيب الإرسال أيضاً: الوظائف تُلقى في الطابور بالترتيب.
        if ($request->hasFile('files')) {
            return $this->queueMediaBatch($request, $contact);
        }

        if ($request->file('file')) {
			$fileType = $request->type;
			$caption = $this->resolveMediaCaption($request, $fileType);
			$organizationId = $this->organizationId;
			$uuid = $contact->uuid;
			$file = $request->file('file');
			$messageUUID = $request->messageUUID;
			$tempMessageId = Request()->get('tempMessageId');
			$fileName = $file->getClientOriginalName();

			// Compress oversized images before upload so they pass WhatsApp's 5MB limit.
			$uploadBytes = file_get_contents($file->getRealPath());
			if ($fileType === 'image' && $file->getSize() > ImageCompressionService::IMAGE_MAX_BYTES) {
				$compressed = ImageCompressionService::compressToLimit($file->getRealPath(), $file->getMimeType());
				if ($compressed === null) {
					return response()->json([
						'success' => false,
						'message' => __('Image is too large to send. Please use a smaller image.'),
					], 422);
				}
				$uploadBytes = $compressed['contents'];
				$fileName = pathinfo($fileName, PATHINFO_FILENAME) . '.' . $compressed['extension'];
			}

			if ($tempMessageId) {
				$ext = pathinfo($fileName, PATHINFO_EXTENSION);
				$tempFilePath = 'temp/send-media/' . uniqid() . '_' . Str::slug(pathinfo($fileName, PATHINFO_FILENAME)) . '.' . $ext;
				Storage::disk('local')->put($tempFilePath, $uploadBytes);
				SendMediaJob::dispatch(
					$organizationId,
					$uuid,
					$fileType,
					$fileName,
					$tempFilePath,
					auth()->id(),
					$tempMessageId,
					$messageUUID,
					$caption
				)->onQueue('high');

				return null;
			}

			// Proactively normalize video to a standard WhatsApp-compatible MP4 (remux + faststart)
			// so Meta accepts it on the first send and never returns error 131053 to the customer.
			$transcodedPath = null;
			if ($fileType === 'video') {
				$transcoder = new VideoTranscodeService();
				if ($transcoder->isAvailable()) {
					$normalized = $transcoder->transcodeForWhatsapp($file->getRealPath());
					if ($normalized !== null) {
						$uploadBytes = file_get_contents($normalized);
						$fileName = pathinfo($fileName, PATHINFO_FILENAME) . '.mp4';
						$transcodedPath = $normalized;
					}
				}
			}

			$storage = Setting::where('key', 'storage_system')->first()->value;
			if ($storage === 'local') {
				$location = 'local';
				$mediaFilePath = Storage::disk('local')->put('public/' . uniqid() . '_' . sanitize_filename_for_storage($fileName), $uploadBytes);
				$mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
			} elseif ($storage === 'aws') {
				$location = 'amazon';
				$s3Path = 'uploads/media/sent/' . $organizationId . '/' . uniqid() . '_' . sanitize_filename_for_storage($fileName);
				$contentType = $fileType === 'video' && $transcodedPath !== null
					? 'video/mp4'
					: whatsapp_media_content_type($fileType, $fileName);
				Storage::disk('s3')->put($s3Path, $uploadBytes, ['ContentType' => $contentType]);
				$mediaFilePath = Storage::disk('s3')->url($s3Path);
				$mediaUrl = $mediaFilePath;
			}

			$response = $this->whatsappService->sendMedia($uuid, $fileType, $fileName, $mediaFilePath, $mediaUrl, $location, $caption, null, auth()->id(), $tempMessageId, $messageUUID);

			if ($transcodedPath !== null && is_file($transcodedPath)) {
				@unlink($transcodedPath);
			}

			return $response;
        }

        $message = trim((string) ($request->message ?? ''));
        if ($message === '') {
            return response()->json([
                'success' => false,
                'message' => __('Message cannot be empty.'),
            ], 422);
        }

        return $this->whatsappService->sendMessage($contact->uuid, $message, auth()->user()->id);
    }

    /**
     * إلقاء مرفقات طلب واحد في الطابور بالترتيب.
     *
     * مسار مستقلّ عن الملف المفرد عمداً: ذاك يخدم زرّ الاختيار وواجهة الـAPI
     * ويحتمل الإرسال المتزامن (بلا tempMessageId)، ولمسه كان يعرّض مسارات
     * قائمة للانكسار. هنا نقتصر على الحالة الوحيدة التي تُرسل دفعةً — من
     * الملحن، ومعها دائماً معرّف مؤقّت لكل ملف.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function queueMediaBatch(object $request, Contact $contact)
    {
        $files = $request->file('files');
        $files = is_array($files) ? $files : [$files];
        $types = (array) $request->input('types', []);
        $tempMessageIds = (array) $request->input('tempMessageIds', []);

        if (count($tempMessageIds) !== count($files)) {
            return response()->json([
                'success' => false,
                'message' => __('Something went wrong. Refresh the page and try again'),
            ], 422);
        }

        $queued = 0;

        foreach ($files as $index => $file) {
            $fileType = $types[$index] ?? 'document';
            $fileName = $file->getClientOriginalName();

            // التعليق للأول وحده: تكراره على كل ملف يُغرق المحادثة.
            $caption = $index === 0 ? $this->resolveMediaCaption($request, $fileType) : null;

            $uploadBytes = file_get_contents($file->getRealPath());

            // الصور فوق حدّ واتساب تُضغط قبل الرفع — نفس منطق الملف المفرد.
            if ($fileType === 'image' && $file->getSize() > ImageCompressionService::IMAGE_MAX_BYTES) {
                $compressed = ImageCompressionService::compressToLimit($file->getRealPath(), $file->getMimeType());
                if ($compressed === null) {
                    return response()->json([
                        'success' => false,
                        'message' => __('Image is too large to send. Please use a smaller image.'),
                    ], 422);
                }
                $uploadBytes = $compressed['contents'];
                $fileName = pathinfo($fileName, PATHINFO_FILENAME) . '.' . $compressed['extension'];
            }

            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $tempFilePath = 'temp/send-media/' . uniqid() . '_'
                . Str::slug(pathinfo($fileName, PATHINFO_FILENAME)) . '.' . $extension;
            Storage::disk('local')->put($tempFilePath, $uploadBytes);

            SendMediaJob::dispatch(
                $this->organizationId,
                $contact->uuid,
                $fileType,
                $fileName,
                $tempFilePath,
                auth()->id(),
                $tempMessageIds[$index],
                $request->messageUUID,
                $caption
            )->onQueue('high');

            $queued++;
        }

        return response()->json(['success' => true, 'queued' => $queued]);
    }

    private function resolveMediaCaption(object $request, ?string $mediaType): ?string
    {
        if ($mediaType === 'audio') {
            return null;
        }

        $caption = trim(strip_tags((string) ($request->caption ?? $request->message ?? '')));
        if ($caption === '' || strcasecmp($caption, 'null') === 0) {
            return null;
        }

        $caption = trim((string) clean($caption));

        return $caption !== '' ? mb_substr($caption, 0, 1024) : null;
    }

    public function sendTemplateMessage(object $request, $uuid)
    {
		$this->initializeWhatsappService();
	
        $template = Template::where('uuid', $request->template)->first();
        $contact = $this->findContactByUuidInOrganization((string) $uuid);
        if (!$contact) {
            return (object) [
                'success' => false,
                'message' => __('Contact not found'),
            ];
        }

        $this->assertConversationAccess($contact);
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
                            
                            if (empty($uploadedFile)) {
                                throw new \Exception('Failed to upload file to S3 storage');
                            }
                            
                            $mediaFilePath = Storage::disk('s3')->url($uploadedFile);
            
                            $mediaUrl = $mediaFilePath;
                        }

                        if (!empty($mediaUrl)) {
                            $contentType = $this->getContentTypeFromUrl($mediaUrl);
                            $mediaSize = $this->getMediaSizeInBytesFromUrl($mediaUrl);
                        } else {
                            $contentType = null;
                            $mediaSize = null;
                        }

                        //save media
                        $chatMedia = new ChatMedia;
                        $chatMedia->name = $fileName;
                        $chatMedia->location = $storage == 'aws' ? 'amazon' : 'local';
                        $chatMedia->path = $mediaUrl;
                        $chatMedia->type = $contentType;
                        $chatMedia->size = $mediaSize;
                        $chatMedia->created_at =  now();
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
                'deleted_at' => now()
            ]);
    }

    public function clearContactChat($uuid): bool
    {
        $contact = Contact::with('lastChat')
            ->where('uuid', $uuid)
            ->where('organization_id', $this->organizationId)
            ->first();

        if (!$contact) {
            return false;
        }

        $this->assertConversationAccess($contact);
        Chat::where('contact_id', $contact->id)->update([
            'deleted_by' => auth()->user()->id,
            'deleted_at' =>  now()
        ]);

        ChatLog::where('contact_id', $contact->id)->where('entity_type', 'chat')->update([
            'deleted_by' => auth()->user()->id,
            'deleted_at' =>  now()
        ]);
		
		event(new ContactChatDeletedEvent($this->organizationId, $contact->id));

        return true;
    }

    private function getContentTypeFromUrl($url)
    {
        if (empty($url)) {
            return null;
        }
        
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
        if (empty($url)) {
            return null;
        }
        
        try {
            $url = ltrim($url, '/');
            $imageContent = file_get_contents($url);
        
            if ($imageContent !== false) {
                return strlen($imageContent);
            }
        } catch (\Exception $e) {
            Log::error('Error getting media size from URL: ' . $e->getMessage());
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

    public function getChatMessages($contactId, $page = 1, $perPage = 50, ?string $createdAt = null , $entityTypes = [])
    {
        $query = ChatLog::where('contact_id', $contactId)
            ->where('deleted_at', null)
            ->orderBy('created_at', 'desc')
          
			->when($createdAt, function ($q) use ($createdAt) {
				$q->where('created_at', '>=', $createdAt);
			})
			->when(count($entityTypes), function ($q) use ($entityTypes) {
				$q->whereIn('entity_type', $entityTypes);
			})
			;

        $chatLogs = ($page && $perPage)
            ? $query->paginate($perPage, ['*'], 'page', $page)
            : $query->get();

        $chatIds = $chatLogs->where('entity_type', 'chat')->pluck('entity_id')->unique()->filter()->values()->all();
        $ticketIds = $chatLogs->where('entity_type', 'ticket')->pluck('entity_id')->unique()->filter()->values()->all();
        $noteIds = $chatLogs->where('entity_type', 'notes')->pluck('entity_id')->unique()->filter()->values()->all();

        $chatsMap = !empty($chatIds)
            ? Chat::with('media', 'user', 'logs')->whereIn('id', $chatIds)->get()->keyBy('id')
            : collect();
        $ticketLogsMap = !empty($ticketIds)
            ? ChatTicketLog::whereIn('id', $ticketIds)->get()->keyBy('id')
            : collect();
        $notesMap = !empty($noteIds)
            ? ChatNote::whereIn('id', $noteIds)->get()->keyBy('id')
            : collect();

        $chats = [];
        foreach ($chatLogs as $chatLog) {
            $value = null;
            if ($chatLog->entity_type === 'chat') {
                $value = $chatsMap->get($chatLog->entity_id);
            } elseif ($chatLog->entity_type === 'ticket') {
                $value = $ticketLogsMap->get($chatLog->entity_id);
            } elseif ($chatLog->entity_type === 'notes') {
                $value = $notesMap->get($chatLog->entity_id);
            }
            $chats[] = [['type' => $chatLog->entity_type, 'value' => $value]];
        }

        $isPaginated = $chatLogs instanceof \Illuminate\Pagination\LengthAwarePaginator;

        return [
            'messages' => array_reverse($chats),
            'hasMoreMessages' => $isPaginated ? $chatLogs->hasMorePages() : false,
            'nextPage' => $isPaginated ? $chatLogs->currentPage() + 1 : null,
        ];
    }
	
    private function ensureChatTicketsExist()
    {
        $contactsWithoutTickets = DB::table('contacts')
            ->select('contacts.id')
            ->where('contacts.organization_id', $this->organizationId)
            ->whereNull('contacts.deleted_at')
            ->whereNotNull('contacts.latest_chat_created_at')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('chat_tickets')
                    ->whereColumn('chat_tickets.contact_id', 'contacts.id');
            })
            ->limit(500)
            ->pluck('id');

        if ($contactsWithoutTickets->isEmpty()) {
            return;
        }

        $now = now();
        $ticketsData = $contactsWithoutTickets->map(function ($contactId) use ($now) {
            return [
                'contact_id' => $contactId,
                'assigned_to' => null,
                'status' => 'open',
                'is_latest' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->toArray();

        collect($ticketsData)->chunk(500)->each(function ($chunk) {
            DB::table('chat_tickets')->insertOrIgnore($chunk->toArray());
        });
    }
    public function blockContact(Organization $organization, Contact $contact)
    {
        $this->assertConversationAccess($contact);
        $metadata = json_decode($organization->metadata);
        //  $organizationId = $organization->id;
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
                return [
                    'status'=>false ,
                    'message'=>__('Cannot block contact. They must have messaged you within the last 24 hours.')
                ];
            }
            if (isset($result['errors']['code'])) {
    
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
        $this->assertConversationAccess($contact);
        $metadata = json_decode($organization->metadata);
        //  $organizationId = $organization->id;
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
   //     $wabaId = $config['whatsapp']['waba_id'] ?? null;

        
    

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

    /**
     * مصفوفة مختصرة للـ contact المستخدم في صفحة المحادثة فقط (بدل إرسال الـ model كاملاً).
     * المفاتيح مأخوذة من: Index.vue، ChatHeader، ChatForm، ContactInfo، CampaignForm.
     */
    public static function contactPayloadForChatView(Contact $contact): array
    {
        $messagingWindow = MessagingWindowHelper::payloadForContact($contact);

        return array_merge(
            $contact->only([
                'id',
                'uuid',
                'is_blocked',
                'first_name',
                'last_name',
                'email',
                'metadata',
                'is_favorite',
                'address',
                'avatar',
            ]),
            $messagingWindow,
            [
                'full_name' => $contact->full_name,
                'formatted_phone_number' => $contact->formatted_phone_number,
                'contact_groups' => $contact->relationLoaded('contactGroups')
                    ? $contact->contactGroups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values()->all()
                    : [],
                'contact_categories' => $contact->relationLoaded('contactCategories')
                    ? $contact->contactCategories->map(fn ($c) => [
                        'id' => $c->id,
                        'uuid' => $c->uuid,
                        'name' => $c->name,
                        'background_color' => $c->background_color ?? '#22c55e',
                        'text_color' => $c->text_color ?? '#ffffff',
                    ])->values()->all()
                    : [],
            ]
        );
    }
}
