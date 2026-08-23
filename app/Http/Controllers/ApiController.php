<?php

namespace App\Http\Controllers;

use App\Helpers\ChatMediaUploadHelper;
use App\Helpers\MessagingWindowHelper;
use App\Helpers\WebhookHelper;
use App\Http\Requests\Api\StoreContactRequest;
use App\Http\Resources\AutoReplyResource;
use App\Http\Resources\ContactCategoryResource;
use App\Http\Resources\ContactGroupResource;
use App\Http\Resources\ContactResource;
use App\Http\Resources\TemplateResource;
use App\Models\AutoReply;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatNote;
use App\Models\ChatTicket;
use App\Models\ChatTicketLog;
use App\Models\Contact;
use App\Models\ContactCategory;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Shortcut;
use App\Models\Template;
use App\Models\User;
use App\Services\ChatService;
use App\Services\ContactService;
use App\Services\MediaService;
use App\Services\PhoneService;
use App\Services\ActivityLogger;
use App\Services\AgentPerformanceService;
use App\Services\SubscriptionService;
use App\Services\WhatsappService;
use App\Support\ChatStatus;
use App\Support\JsonText;
use App\Support\OrganizationRole;
use App\Traits\TemplateTrait;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Locale;
use Propaganistas\LaravelPhone\PhoneNumber;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApiController extends Controller
{
    use TemplateTrait;

    private $whatsappService;

    /**
     * List contacts for the organization (mobile + API).
     *
     * Search (`?search=`) runs server-side over ALL non-deleted contacts in the
     * current organization (name, phone, email) — not over a limited "last N" slice.
     * `per_page` (default 10, max 100) and `page` only paginate matching rows.
     *
     * Mobile clients must call this endpoint with `search` on each keystroke/submit
     * instead of filtering a locally loaded infinite-scroll cache.
     *
     * @return \Illuminate\Http\Response
     */
    public function listContacts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            // Page size only — does NOT limit which contacts are searchable.
            'per_page' => 'integer|min:1|max:100',
            'search' => 'sometimes|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 10);
        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }
        $searchTerm = $request->filled('search') ? (string) $request->input('search') : null;

        $contacts = Contact::where('organization_id', $organizationId)
            ->with(['contactGroups', 'contactCategories:id,name,uuid,background_color,text_color'])
            ->searchTerm($searchTerm)
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
        return ContactResource::collection($contacts);
    }

    /**
     * Create a new contact.
     *
     * @param  \App\Http\Requests\CreateContactRequest  $request
     * @return \Illuminate\Http\Response
     */
    
    public function storeContact(StoreContactRequest $request)
    {
        
        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }
        if (!SubscriptionService::isSubscriptionActive($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'message' => __('Please renew or subscribe to a plan to continue!', [], getApiLang()),
            ], 403);
        }

        if (SubscriptionService::isSubscriptionFeatureLimitReached($organizationId, 'contacts_limit')) {
            return response()->json([
                'statusCode' => 403,
                'message' => __('You have reached your limit of contacts. Please upgrade your account to add more!', [], getApiLang()),
            ], 403);
        }

        try {
            $contactService = new ContactService($organizationId);
            $contact = $contactService->store($request, null); // null for create
            $this->logMobileActivity(ActivityLogger::CONTACT_CREATED, $contact, [], (int) $organizationId);
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'statusCode' => 200,
                    'success' => true,
                    'data' => [
                        'uuid' => $contact->uuid,
                    ],
                    'message' => __('Request processed successfully', [], getApiLang())
                ], 200);
            }
            return response()->json([
                'statusCode' => 200,
                'success' => true,
                'id' => $contact->uuid,
                'message' => __('Request processed successfully', [], getApiLang())
            ], 200);
        } catch (QueryException $e) {
            if ($this->isDuplicateContactPhoneException($e)) {
                return $this->duplicateContactPhoneResponse($request);
            }
            Log::error('storeContact failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'statusCode' => 500,
                    'success' => false,
                    'data' => [],
                    'message' => __('Request unable to be processed', [], getApiLang())
                ], 500);
            }
            return response()->json([
                'statusCode' => 500,
                'success' => false,
                'message' => __('Request unable to be processed', [], getApiLang())
            ], 500);
        } catch (\Exception $e) {
            Log::error('storeContact failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'statusCode' => 500,
                    'success' => false,
                    'data' => [],
                    'message' => __('Request unable to be processed', [], getApiLang())
                ], 500);
            }
            return response()->json([
                'statusCode' => 500,
                'success' => false,
                'message' => __('Request unable to be processed', [], getApiLang())
            ], 500);
        }
    }

    /**
     * Update an existing contact.
     */
    public function updateContact(StoreContactRequest $request, string $idOrUUID)
    {
        $organizationId = $request->organization;
        $uuid = $idOrUUID;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
            $contactService = new ContactService($organizationId);
            $contact = $contactService->findInOrganizationByIdOrUuid($idOrUUID);
            if (!$contact) {
                return response()->json([
                    'statusCode' => 404,
                    'success' => false,
                    'data' => [],
                    'message' => __('Contact not found', [], getApiLang())
                ], 404);
            }
            $uuid = $contact->uuid;
             
        }
        if (!SubscriptionService::isSubscriptionActive($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'message' => __('Please renew or subscribe to a plan to continue!', [], getApiLang()),
            ], 403);
        }

        try {
            $contactService = new ContactService($organizationId);
            $contact = $contactService->store($request, $uuid); // uuid for update
            $this->logMobileActivity(ActivityLogger::CONTACT_UPDATED, $contact, [], (int) $organizationId);
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'statusCode' => 200,
                    'success' => true,
                    'data' => [
                        'uuid' => $contact->uuid,
                    ],
                    'message' => __('Request processed successfully', [], getApiLang())
                ], 200);
            }
            return response()->json([
                'statusCode' => 200,
                'id' => $contact->uuid,
                'message' => __('Request processed successfully', [], getApiLang())
            ], 200);
        } catch (QueryException $e) {
            if ($this->isDuplicateContactPhoneException($e)) {
                return $this->duplicateContactPhoneResponse($request);
            }
            Log::error('updateContact failed', [
                'organization_id' => $organizationId,
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'statusCode' => 500,
                    'success' => false,
                    'data' => [],
                    'message' => __('Request unable to be processed', [], getApiLang())
                ], 500);
            }
            return response()->json([
                'statusCode' => 500,
                'message' => __('Request unable to be processed', [], getApiLang())
            ], 500);
        } catch (\Exception $e) {
            Log::error('updateContact failed', [
                'organization_id' => $organizationId,
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'statusCode' => 500,
                    'success' => false,
                    'data' => [],
                    'message' => __('Request unable to be processed', [], getApiLang())
                ], 500);
            }
            return response()->json([
                'statusCode' => 500,
                'message' => __('Request unable to be processed', [], getApiLang())
            ], 500);
        }
    }

    /**
     * MySQL duplicate key on contacts phone unique index.
     */
    private function isDuplicateContactPhoneException(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    /**
     * Map a contacts unique-phone DB violation to the same 400 clients expect from UniquePhone.
     */
    private function duplicateContactPhoneResponse(Request $request)
    {
        $message = __('This phone number already exists', [], getApiLang());

        if ($request->is('api/v1/*')) {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'data' => [],
                'message' => $message,
                'errors' => [
                    'phone' => [$message],
                ],
            ], 400);
        }

        return response()->json([
            'statusCode' => 400,
            'success' => false,
            'message' => $message,
            'errors' => [
                'phone' => [$message],
            ],
        ], 400);
    }
    public function getContactDetail(Request $request, $id)
    {
        $organizationId = $request->is('api/v1/*')
            ? (int) $request->user()->current_mobile_organization_id
            : (int) $request->organization;

        $contactService = new ContactService($organizationId);
        $contact = $contactService->findInOrganizationByIdOrUuid($id);
        if (!$contact) {
            return response()->json([
                'statusCode' => 404,
                'success' => false,
                'data' => [],
                'message' => __('Contact not found', [], getApiLang())
            ], 404);
        }
        $chatTicket = ChatTicket::where('contact_id', $contact->id)->first();
        $contact->chat_ticket = $chatTicket ?? null;

        $contact->load(['contactGroups', 'contactCategories:id,name,uuid,background_color,text_color']);
        $contact->groups = $contact->contactGroups->map(function ($group) {
            return [
                'id' => $group->id,
                'name' => $group->name,
            ];
        });
        $contact->categories = $contact->contactCategories->map(function ($cat) {
            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'background_color' => $cat->background_color ?? '#22c55e',
                'text_color' => $cat->text_color ?? '#ffffff',
            ];
        });
        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'data' => $contact,
            'message' => __('Contact detail fetched successfully', [], getApiLang())
        ], 200);
    }
    /**
     * Delete a contact.
     *
     * @param  \App\Models\Contact  $contact
     * @return \Illuminate\Http\Response
     */
    public function destroyContact(Request $request, $uuid)
    {
        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }
        try {
            $contactService = new ContactService($organizationId);
            $contact = $contactService->findInOrganizationByIdOrUuid($uuid);
            if (!$contact) {
                return response()->json([
                    'statusCode' => 404,
                    'success' => false,
                    'data' => [],
                    'message' => __('Contact not found', [], getApiLang())
                ], 404);
            }
            $this->logMobileActivity(ActivityLogger::CONTACT_DELETED, $contact, [], (int) $organizationId);
            $contactService->delete([$contact->uuid]);
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'statusCode' => 200,
                    'success' => true,
                    'data' => [],
                    'message' => __('Request processed successfully', [], getApiLang())
                ], 200);
            }
            return response()->json([
                'statusCode' => 200,
                'id' => $uuid,
                'message' => __('Request processed successfully', [], getApiLang())
            ], 200);
        } catch (\Exception $e) {
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'statusCode' => 500,
                    'success' => false,
                    'data' => [],
                    'message' => __('Request unable to be processed', [], getApiLang())
                ], 500);
            }
            return response()->json([
                'statusCode' => 500,
                'message' => __('Request unable to be processed', [], getApiLang())
            ], 500);
        }
    }

   
    public function listContactGroups(Request $request)
    {
        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }

        $contactGroups = ContactGroup::where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->orderBy('name')
            ->get();

        return ContactGroupResource::collection($contactGroups);
    }

   
    public function storeContactGroup(Request $request, $uuid = null)
    {
        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }
        if ($request->isMethod('post')) {
            $rules = [
                'name' => [
                    'required',
                    Rule::unique('contact_groups', 'name')->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId)
                            ->where('deleted_at', null);
                    }),
                ],
            ];
        } else {
            $rules = [
                'name' => [
                    'required',
                    Rule::unique('contact_groups', 'name')->where(function ($query) use ($organizationId, $uuid) {
                        return $query->where('organization_id', $organizationId)
                            ->where('deleted_at', null)
                            ->whereNotIn('uuid', [$uuid]);
                    }),
                ],
            ];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'statusCode' => 400,
                'message' => __('The given data was invalid.'),
                'errors' => $validator->errors()
            ], 400);
        }

        if (!SubscriptionService::isSubscriptionActive($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'message' => __('Please renew or subscribe to a plan to continue!'),
            ], 403);
        }

        try {
            $contactGroup = $request->isMethod('post') ? new ContactGroup() : ContactGroup::where('uuid', $uuid)->firstOrFail();
            $contactGroup->organization_id = $organizationId;
			if($request->has('name')){
				$contactGroup->name = $request->name;
			}
            $contactGroup->created_by = 0;
            $contactGroup->save();

            ActivityLogger::log(
                $request->isMethod('post') ? ActivityLogger::CONTACT_GROUP_CREATED : ActivityLogger::CONTACT_GROUP_UPDATED,
                $contactGroup->name,
                'contact_group',
                $contactGroup->id,
                [],
                (int) $organizationId
            );

            // Prepare a clean contact object for webhook
            $cleanContactGroup = $contactGroup->makeHidden(['id', 'organization_id', 'created_by']);

            // Trigger webhook
            WebhookHelper::triggerWebhookEvent($request->isMethod('post') ? 'group.created' : 'group.updated', $cleanContactGroup, $request->organization);

            return response()->json([
                'statusCode' => 200,
                'success' => true,
                'data' => [
                    'uuid' => $contactGroup->uuid,
                ],
                'id' => $contactGroup->uuid,
                'message' => __('Request processed successfully')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                
                'statusCode' => 500,
                'success' => false,
                'data' => [],
                'message' => __('Request unable to be processed')
            ], 500);
        }
    }

    /**
     * Delete a contact group.
     *
     * @param  \App\Models\ContactGroup  $contactGroup
     * @return \Illuminate\Http\Response
     */
    public function destroyContactGroup(Request $request, $uuid)
    {
        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }
        try {
            $contactGroup = ContactGroup::where('organization_id', $organizationId)->where('uuid', $uuid)->firstOrFail();
            $contactGroup->deleted_at = date('Y-m-d H:i:s');
            $contactGroup->save();

            ActivityLogger::log(
                ActivityLogger::CONTACT_GROUP_DELETED,
                $contactGroup->name,
                'contact_group',
                $contactGroup->id,
                [],
                (int) $organizationId
            );

            //Remove contact associations
            Contact::where('contact_group_id', $contactGroup->id)->update([
                'contact_group_id' => null
            ]);

            // Trigger webhook with deleted contacts
            $deletedGroups[] = [
                'uuid' => $uuid,
                'deleted_at' => now()->toISOString(),
            ];

            WebhookHelper::triggerWebhookEvent('group.deleted', [
                'list' => $deletedGroups
            ], $organizationId);

            return response()->json([
                'statusCode' => 200,
                'success' => true,
                'data' => [
                    'uuid' => $uuid,
                ],
                'id' => $uuid,
                'message' => __('Request processed successfully')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => [],
                'statusCode' => 500,
                'message' => __('Request unable to be processed')
            ], 500);
        }
    }

    public function listContactCategories(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 10);
        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }
        $contactCategories = ContactCategory::where('organization_id', $organizationId)
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $page);

        return ContactCategoryResource::collection($contactCategories);
    }

    public function storeContactCategory(Request $request, $uuid = null)
    {
        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }
        if (!SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'contact_categories_enabled')) {
            return response()->json([
                'statusCode' => 403,
                'message' => __('Contact Categories are not available in your plan.'),
            ], 403);
        }
        if ($request->isMethod('post')) {
            $rules = [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('contact_categories', 'name')->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId);
                    }),
                ],
                'background_color' => [
                    'nullable',
                    'string',
                    'max:20',
                    'regex:/^#([0-9a-fA-F]{6})$/',
                ],
                'text_color' => [
                    'nullable',
                    'string',
                    'max:20',
                    'regex:/^#([0-9a-fA-F]{6})$/',
                ],
            ];
        } else {
            $rules = [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('contact_categories', 'name')->where(function ($query) use ($organizationId, $uuid) {
                        return $query->where('organization_id', $organizationId)
                            ->whereNotIn('uuid', [$uuid]);
                    }),
                ],
                'background_color' => [
                    'nullable',
                    'string',
                    'max:20',
                    'regex:/^#([0-9a-fA-F]{6})$/',
                ],
                'text_color' => [
                    'nullable',
                    'string',
                    'max:20',
                    'regex:/^#([0-9a-fA-F]{6})$/',
                ],
            ];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'statusCode' => 400,
                'message' => __('The given data was invalid.'),
                'errors' => $validator->errors()
            ], 400);
        }

        if (!SubscriptionService::isSubscriptionActive($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'message' => __('Please renew or subscribe to a plan to continue!'),
            ], 403);
        }

        try {
            $contactCategory = $request->isMethod('post') ? new ContactCategory() : ContactCategory::where('uuid', $uuid)->where('organization_id', $organizationId)->firstOrFail();
            $contactCategory->organization_id = $organizationId;
            if($request->has('name')){
				$contactCategory->name = $request->name;
			}
			if($request->has('background_color')){
				$contactCategory->background_color = $request->background_color ?? '#22c55e';
			}
			if($request->has('text_color')){
				$contactCategory->text_color = $request->text_color ?? '#ffffff';
			}
  
            $contactCategory->save();

            return response()->json([
                'statusCode' => 200,
                'success' => true,
                'data' => [
                    'uuid' => $contactCategory->uuid,
                    'background_color' => $contactCategory->background_color,
                    'text_color' => $contactCategory->text_color,
                ],
                'id' => $contactCategory->uuid,
                'message' => __('Request processed successfully')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'statusCode' => 500,
                'success' => false,
                'data' => [],
                'message' => __('Request unable to be processed')
            ], 500);
        }
    }

    public function destroyContactCategory(Request $request, $uuid)
    {
        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }
        try {
            $contactCategory = ContactCategory::where('organization_id', $organizationId)->where('uuid', $uuid)->firstOrFail();
            $contactCategory->contacts()->detach();
            $contactCategory->delete();

            return response()->json([
                'statusCode' => 200,
                'success' => true,
                'data' => [
                    'uuid' => $uuid,
                ],
                'message' => __('Request processed successfully')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => [],
                'statusCode' => 500,
                'message' => __('Request unable to be processed')
            ], 500);
        }
    }

    public function listCannedReplies(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100', // Adjust max per_page limit as needed
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 10);

        $rows = AutoReply::where('organization_id', $request->organization)
            ->where('deleted_at', null)
            ->paginate($perPage, ['*'], 'page', $page);

        return AutoReplyResource::collection($rows);
    }

    /**
     * Create a new canned reply.
     *
     * @param  \App\Http\Requests\CreateCannedReplyRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function storeCannedReply(Request $request, $uuid = null)
    {
        $rules = [
            'name' => 'required',
            'trigger' => 'required',
            'match_criteria' => 'required|in:exact match,contains',
            'response_type' => 'required|in:text,image,audio',
            'response' => 'required',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'statusCode' => 400,
                'message' => __('The given data was invalid.'),
                'errors' => $validator->errors()
            ], 400);
        }

        if (!SubscriptionService::isSubscriptionActive($request->organization)) {
            return response()->json([
                'statusCode' => 403,
                'message' => __('Please renew or subscribe to a plan to continue!'),
            ], 403);
        }

        $organizationId = $request->organization;

        if ($request->isMethod('post')) {
            if (SubscriptionService::isSubscriptionFeatureLimitReached($organizationId, 'canned_replies_limit')) {
                return response()->json([
                    'statusCode' => 403,
                    'message' => __('You\'ve reached your limit. Upgrade your account'),
                ], 403);
            }
        }

        try {
            $model = $request->isMethod('post')
                ? new AutoReply()
                : AutoReply::where('uuid', $uuid)
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at')
                    ->firstOrFail();

            $model->name = $request->name;
            $model->trigger = $request->trigger;
            $model->match_criteria = $request->match_criteria;

            $metadata = [
                'type' => $request->response_type,
                'data' => [],
            ];

            if ($request->response_type === 'image' || $request->response_type === 'audio') {
                if ($request->hasFile('response')) {
                    $uploadedMedia = MediaService::upload($request->file('response'));

                    $metadata['data']['file']['name'] = $uploadedMedia['name'];
                    $metadata['data']['file']['location'] = $uploadedMedia['path'];
                } else {
                    $existingMetadata = json_decode($model->metadata ?? '{}');
                    $media = $existingMetadata->data ?? null;

                    if (!$media || !isset($media->file->name, $media->file->location)) {
                        return response()->json([
                            'statusCode' => 400,
                            'message' => __('The given data was invalid.'),
                            'errors' => ['response' => [__('Response file is required.')]],
                        ], 400);
                    }

                    $metadata['data']['file']['name'] = $media->file->name;
                    $metadata['data']['file']['location'] = $media->file->location;
                }
            } elseif ($request->response_type === 'text') {
                $metadata['data']['text'] = $request->response;
            } else {
                $metadata['data']['template'] = $request->response;
            }

            $model->metadata = json_encode($metadata);
            $model->updated_at = now();

            if ($request->isMethod('post')) {
                $model->organization_id = $organizationId;
                $model->created_by = 0;
                $model->created_at = now();
            }

            $model->save();

            // Prepare a clean contact object for webhook
            $cleanModel = $model->makeHidden(['id', 'organization_id', 'created_by']);

            // Trigger webhook
            WebhookHelper::triggerWebhookEvent($request->isMethod('post') ? 'autoreply.created' : 'autoreply.updated', $cleanModel, $organizationId);

            return response()->json([
                'statusCode' => 200,
                'id' => $model->uuid,
                'message' => __('Request processed successfully')
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'statusCode' => 404,
                'message' => __('Resource not found.'),
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'statusCode' => 500,
                'message' => __('Request unable to be processed')
            ], 500);
        }
    }

    /**
     * Delete a canned reply.
     *
     * @param  \App\Models\CannedReply  $cannedReply
     * @return \Illuminate\Http\Response
     */
    public function destroyCannedReply(Request $request, $uuid)
    {
        try {
            $autoreply = AutoReply::where('organization_id', $request->organization)->where('uuid', $uuid)->firstOrFail();
            $autoreply->deleted_at = now();
            $autoreply->deleted_by = 0;
            $autoreply->save();

            // Trigger webhook
            WebhookHelper::triggerWebhookEvent('autoreply.deleted', [
                'list' => [
                    'uuid' => $uuid,
                    'deleted_at' => now()->toISOString()
                ],
            ], $request->organization);

            return response()->json([
                'statusCode' => 200,
                'id' => $uuid,
                'message' => __('Request processed successfully')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'statusCode' => 500,
                'message' => __('Request unable to be processed')
            ], 500);
        }
    }

    /**
     * Send a chat message.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function sendMessage(Request $request)
    {
        $organizationId = $request->organization;
        $mobileOrganizationId = $request->user()?->current_mobile_organization_id;
        // Mobile endpoints use the current_mobile_organization_id (never rely on request->organization).
        if ($mobileOrganizationId && ($request->is('api/v1/*') || $request->is('api/send-msg'))) {
            $organizationId = $mobileOrganizationId;
            $request->merge(['tempMessageId' => -1]); // to use queue to send message in background
        }
        $rules = [
            'phone' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (!PhoneService::isValid($value)) {
                    $fail('The phone number is not valid.');
                }
            }],
            'message' => 'required|string|min:1',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => __('The provided data is invalid.'),
                'errors' => $validator->errors()
            ], 400);
        }

        if (!SubscriptionService::isSubscriptionActive($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('Please renew or subscribe to a plan to continue!'),
            ], 403);
        }

        //Check if the whatsapp connection exists
        if ($whatsappError = $this->whatsappNotConnectedResponse($organizationId)) {
            return $whatsappError;
        }

        $contact = $this->resolveContactByPhone($request, $organizationId);

        if (!MessagingWindowHelper::isMessagingWindowOpen($contact)) {
            return MessagingWindowHelper::closedWindowApiJsonResponse();
        }

        $this->initializeWhatsappService($organizationId);
        $type = !isset($request->buttons) ? 'text' : 'interactive buttons';

        $header = [];
        if ($request->header) {
            $header['type'] = 'text';
            $header['text'] = clean($request->header);
        }
        
        $message = $this->whatsappService->sendMessage($contact->uuid, $request->message, 0, $type, $request->buttons, $header, $request->footer);

        // مسار التطبيق لا يمرّ بـ ChatService، فلولا هذا السطر لغاب كل ما يرسله
        // الموظفون من الموبايل عن سجلّ النشاط — وهو المسار الأكثر استعمالاً.
        ActivityLogger::log(
            ActivityLogger::MESSAGE_SENT,
            trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $contact->phone,
            'contact',
            $contact->id,
            [],
            (int) $organizationId
        );

        $data = $message;
        if ($message === null) {
            $data = [
                'queued' => true,
                'contact_id' => $contact->id,
                'contact_uuid' => $contact->uuid,
                'phone' => $contact->phone,
            ];
        }
        
        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => $message === null ? __('Message queued for sending.') : null,
            'data' => $data,
        ], 200);
    }
    public function sendMsg(Request $request)
    {
        if ($request->get('type') == 'text') {
            return $this->sendMessage($request);
        }
        return $this->sendFileMessage($request);
    }
    public function sendTemplateMessage(Request $request)
    {
        $rules = [
            'phone' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (!PhoneService::isValid($value)) {
                    $fail('The phone number is not valid.');
                }
            }],
            'template.name' => 'required',
            'template.language' => 'required',
        ];

        
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => __('The provided data is invalid.'),
                'errors' => $validator->errors()
            ], 400);
        }
        
        $organizationId = $request->organization;
        $sendByQueue = false;
        

        if (!SubscriptionService::isSubscriptionActive($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('Please renew or subscribe to a plan to continue!'),
            ], 403);
        }
        
        //Check if the whatsapp connection exists
        if ($whatsappError = $this->whatsappNotConnectedResponse($organizationId)) {
            return $whatsappError;
        }

        $contact = $this->resolveContactByPhone($request, $organizationId);

        $this->initializeWhatsappService($organizationId);
        $responseObject = $this->whatsappService->sendTemplateMessage($contact->uuid, $request->template, 0, null, null, $sendByQueue);
        $this->logMobileActivity(ActivityLogger::TEMPLATE_SENT, $contact, ['template' => $request->template], (int) $organizationId);

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Template sent successfully'),
            'data' => $responseObject
        ], 200);
    }
    /**
     * * دي هستخدمها مه عالموبايل بحيث ابعت التمبلت بمعرفه الاي دي الخاص به علي العكس اللي معمولة قبل كدا
     * * وكمان هنا هستخدم ال Queue للارسال
     */
    public function sendTemplateMessageByUUID(Request $request)
    {
        $rules = [
            'phone' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (!PhoneService::isValid($value)) {
                    $fail('The phone number is not valid.');
                }
            }],
            'template_uuid' => 'required|exists:templates,uuid',
        ];

        
        $validator = Validator::make($request->all(), $rules);


        if ($validator->fails()) {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => __('The provided data is invalid.'),
                'errors' => $validator->errors()
            ], 400);
        }
        $organizationId = $request->user()?->current_mobile_organization_id
            ?: $request->user()?->current_web_organization_id;

        if(!$organizationId){
            $organizationId =  session()->get('current_organization');
        }
     
     
        $sendByQueue = true;
        $template = Template::where('uuid', $request->template_uuid)->where('organization_id', $organizationId)->first();
        if (!$template) {
            return response()->json([
                'statusCode' => 404,
                'success' => false,
                'message' => __('Template not found!'),
            ], 404);
        }

        $templateContent = [
            'name' => $template->name,
            'language' => [
                'code' => $template->language,
            ],
        ];

        $request->merge(['template' => $templateContent]);
        if (!SubscriptionService::isSubscriptionActive($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('Please renew or subscribe to a plan to continue!'),
            ], 403);
        }
        
        //Check if the whatsapp connection exists
        if ($whatsappError = $this->whatsappNotConnectedResponse($organizationId)) {
            return $whatsappError;
        }

        $contact = $this->resolveContactByPhone($request, $organizationId);

        // If we have saved template parameters (e.g. auth template from settings), build full template with components for WhatsApp.
        $templateParameters = $request->input('template_parameters');
        if ($templateParameters && isset($templateParameters['template']) && $templateParameters['template'] === $template->uuid) {
            $metadata = json_decode(json_encode($templateParameters));
            $templateContent = $this->buildTemplate($template->name, $template->language, $metadata, $contact);
            $request->merge(['template' => $templateContent]);
        }

        // Extract the UUID of the contact
        $this->initializeWhatsappService($organizationId);
        $responseObject = $this->whatsappService->sendTemplateMessage($contact->uuid, $request->template, 0, null, null, $sendByQueue);
        $this->logMobileActivity(ActivityLogger::TEMPLATE_SENT, $contact, ['template' => $request->template], (int) $organizationId);

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Template sent successfully'),
            'data' => $responseObject
        ], 200);
    }

    /**
     * Send the auth template (configured in Settings → General → Auth Template) to a contact.
     * Used by the mobile app. Requires phone in body. If auth template is not set, returns error
     * asking the user to go to Settings → General Settings to select the auth template.
     */
    public function sendAuthTemplate(Request $request)
    {
        $rules = [
            'phone' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (!PhoneService::isValid($value)) {
                    $fail(__('The phone number is not valid.'));
                }
            }],
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => __('The provided data is invalid.'),
                'errors' => $validator->errors()
            ], 400);
        }

        $organizationId = $request->user()->current_mobile_organization_id ?? session()->get('current_organization');
        $organization = Organization::find($organizationId);
        if (!$organization) {
            return response()->json([
                'statusCode' => 404,
                'success' => false,
                'message' => __('Organization not found.'),
            ], 404);
        }

        $metadata = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $templateUUID = $metadata['auth_template'] ?? null;
        $template = $templateUUID ? Template::where('uuid', $templateUUID)->where('organization_id', $organizationId)->first() : null;

        if (!$template) {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => __('Auth template is not set. Please go to Settings → General Settings to select the auth template.'),
            ], 400);
        }

        $phone = $request->phone;
        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }
        $phone = (new PhoneNumber($phone))->formatE164();

        $request->merge([
            'template_uuid' => $template->uuid,
            'phone' => $phone,
        ]);

        $authParams = $metadata['auth_template_parameters'] ?? null;
        if ($authParams && isset($authParams['template']) && $authParams['template'] === $template->uuid) {
            $request->merge(['template_parameters' => $authParams]);
        }

        return $this->sendTemplateMessageByUUID($request);
    }
    
    public function sendMediaMessage(Request $request)
    {
        $organizationId = $request->organization;
        
        // if( $request->is('api/v1/*')){
        // 	$organizationId = $request->user()->current_mobile_organization_id;
        // }
        $rules = [
            'phone' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (!PhoneService::isValid($value)) {
                    $fail('The phone number is not valid.');
                }
            }],
            'media_type' => 'required',
            'media_url' => 'required',
            'caption' => 'required',
            'file_name' => 'required',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => __('The provided data is invalid.'),
                'errors' => $validator->errors()
            ], 400);
        }

        if (!SubscriptionService::isSubscriptionActive($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('Please renew or subscribe to a plan to continue!'),
            ], 403);
        }

        //Check if the whatsapp connection exists
        if ($whatsappError = $this->whatsappNotConnectedResponse($organizationId)) {
            return $whatsappError;
        }

        $contact = $this->resolveContactByPhone($request, $organizationId);

        if (!MessagingWindowHelper::isMessagingWindowOpen($contact)) {
            return MessagingWindowHelper::closedWindowApiJsonResponse();
        }

        $this->initializeWhatsappService($organizationId);
        $caption = trim((string) ($request->caption ?? ''));
        $caption = $caption !== '' ? mb_substr($caption, 0, 1024) : null;

        $message = $this->whatsappService->sendMedia(
            $contact->uuid,
            $request->media_type,
            $request->file_name,
            $request->media_url,
            $request->media_url,
            'amazon',
            $caption
        );
        
        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'data' => $message
        ], 200);
    }

    /**
     * * دي هنستخدم فيها الكيو للرفع وهنا بنرفع الملف نفسه مش بالرابط زي الميسود اللي فوق
     */
    public function sendFileMessage(Request $request)
    {
        $organizationId = $request->user()->current_mobile_organization_id;
        $request->merge(['tempMessageId' => -1]); // to use queue to send message in background

        // إرسال عدّة صور دفعة واحدة يصل كـ file[]، وقاعدة `file` المفردة كانت
        // ترفضه كلّه فيفشل الإرسال بأكمله. نقبل الشكلين ونعامل المفرد كقائمة
        // من عنصر واحد.
        $isBatch = is_array($request->file('file'));
        $fileRule = 'required|file|mimes:jpg,jpeg,png,gif,bmp,webp,svg,ico,heic,heif,mp4,avi,mov,wmv,flv,mkv,webm,3gp,mpeg,mpg,mp3,wav,ogg,aac,m4a,flac,wma,amr,opus,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,rtf,odt,ods,odp';

        $rules = [
            'phone' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (!PhoneService::isValid($value)) {
                    $fail('The phone number is not valid.');
                }
            }],
            'caption' => 'nullable',
        ];

        if ($isBatch) {
            $rules['file'] = 'required|array|max:' . (int) config('chat.max_batch_files', 10);
            $rules['file.*'] = $fileRule;
        } else {
            $rules['file'] = $fileRule;
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => __('The provided data is invalid.'),
                'errors' => $validator->errors()
            ], 400);
        }

        $filesToCheck = $isBatch ? array_values($request->file('file')) : [$request->file('file')];
        foreach ($filesToCheck as $file) {
            $mediaType = self::getFileTypeFromExtension($file->getClientOriginalExtension());
            $maxBytes = ChatMediaUploadHelper::maxUploadBytesForType($mediaType);
            if ($file->getSize() > $maxBytes) {
                return response()->json([
                    'statusCode' => 400,
                    'success' => false,
                    'message' => __('File is larger than the :size limit.', [
                        'size' => ChatMediaUploadHelper::humanMaxSizeForType($mediaType),
                    ]),
                ], 400);
            }
        }
        if (!SubscriptionService::isSubscriptionActive($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('Please renew or subscribe to a plan to continue!'),
            ], 403);
        }

        //Check if the whatsapp connection exists
        if ($whatsappError = $this->whatsappNotConnectedResponse($organizationId)) {
            return $whatsappError;
        }

        $contact = $this->resolveContactByPhone($request, $organizationId);
        $this->logMobileActivity(ActivityLogger::MEDIA_SENT, $contact, [], (int) $organizationId);

        $files = $request->file('file');
        $files = is_array($files) ? array_values($files) : [$files];

        // معرّفات الرسائل من التطبيق: واحد لكل ملف. عمود uuid فريد، فمعرّف
        // واحد لعدّة ملفات يُنجح الأول ويُفشل البقية — نأخذ ما يُرسله بالترتيب
        // وما زاد عن ذلك يُولَّد عندنا.
        $messageUUIDs = $request->get('msg_uuid');
        $messageUUIDs = is_array($messageUUIDs) ? array_values($messageUUIDs) : [$messageUUIDs];

        // +"message": "(#100) Param type must be one of {AUDIO, CONTACTS, DOCUMENT, GIF, IMAGE, INTERACTIVE, LINK_PREVIEW, LOCATION, PIN, REACTION, STICKER, TEMPLATE, TEXT, VIDEO} - got "jpeg"."
        $chatService = new ChatService($organizationId);

        foreach ($files as $index => $file) {
            // التعليق يُرفق بالأول فقط؛ تكراره على كل صورة يُظهره مرات عدداً
            // في محادثة العميل.
            $request->merge([
                'uuid' => $contact->uuid,
                'file' => $file,
                'type' => self::getFileTypeFromExtension($file->getClientOriginalExtension()),
                'caption' => $index === 0 ? $request->caption : null,
                'messageUUID' => $messageUUIDs[$index] ?? null,
            ]);
            $request->files->set('file', $file);

            $chatService->sendMessage($request);
        }

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Message sent successfully'),
            'data' => [
                'queued' => true,
                'files' => count($files),
                'contact_id' => $contact->id,
                'contact_uuid' => $contact->uuid,
                'phone' => $contact->phone,
            ],
        ], 200);
    }

    private function resolveContactByPhone(Request $request, int $organizationId): Contact
    {
        $contactService = new ContactService($organizationId);

        $contact = $contactService->findOrCreateByPhone($request->phone, array_filter([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
        ], fn ($value) => $value !== null && $value !== ''));

        if ($request->filled('first_name') && blank($contact->first_name)) {
            $contact->update([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
            ]);
        }

        return $contact;
    }

    private static function getFileTypeFromExtension($extension)
    {
        $extension = strtolower($extension);
    
        $fileTypes = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'heic', 'heif'],
            'video' => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', '3gp', 'mpeg', 'mpg'],
            'audio' => ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'flac', 'wma', 'amr', 'opus'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'odt', 'ods', 'odp'],
            'gif' => ['gif']
        ];
    
        foreach ($fileTypes as $type => $extensions) {
            if (in_array($extension, $extensions)) {
                return $type;
            }
        }
        throw new \Exception('Invalid file extension: ' . $extension);
        // // Default fallback
        // return 'DOCUMENT';
    }
    /**
     * Store a campaign.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function storeCampaign(Request $request)
    {
        
    }

    private function isWhatsAppConnected($organizationId)
    {
        return $this->whatsappConnectionError($organizationId) === null;
    }

    /**
     * سبب تعذّر الإرسال عبر واتساب، أو null إن كان الربط سليماً.
     *
     * كانت الحالات الثلاث ترجع نصاً واحداً «Please setup your whatsapp
     * account!» فلا يعرف العميل أين الخلل: أهي منشأة خاطئة، أم حساب غير
     * مربوط، أم ربط ناقص البيانات؟ نُميّزها هنا.
     *
     * والفحص السابق كان يكتفي بوجود مفتاح whatsapp، فيمرّ ربط فارغ من
     * access_token ثم يفشل الإرسال لاحقاً برسالة أغمض من هذه.
     */
    private function whatsappConnectionError($organizationId): ?string
    {
        $organization = $organizationId ? Organization::find($organizationId) : null;

        if (!$organization) {
            return __('No active organization was found for your account. Please select an organization and try again.');
        }

        $metadata = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $whatsapp = $metadata['whatsapp'] ?? null;

        if (!is_array($whatsapp) || !$whatsapp) {
            return __('WhatsApp is not connected for :organization. Connect your WhatsApp Business account from Settings → WhatsApp, then try again.', [
                'organization' => $organization->name,
            ]);
        }

        // بيانات الاعتماد الدنيا لأي نداء إلى واجهة واتساب.
        $missing = array_values(array_filter(
            ['access_token', 'phone_number_id', 'waba_id'],
            fn ($key) => empty($whatsapp[$key])
        ));

        if ($missing) {
            return __('The WhatsApp connection for :organization is incomplete (missing: :fields). Reconnect it from Settings → WhatsApp.', [
                'organization' => $organization->name,
                'fields' => implode(', ', $missing),
            ]);
        }

        return null;
    }

    /**
     * ردّ جاهز عند تعذّر الإرسال، أو null إن كان الربط سليماً.
     */
    private function whatsappNotConnectedResponse($organizationId)
    {
        $error = $this->whatsappConnectionError($organizationId);

        if ($error === null) {
            return null;
        }

        return response()->json([
            'statusCode' => 403,
            'success' => false,
            'message' => $error,
        ], 403);
    }

    private function initializeWhatsappService($organizationId)
    {
        $config = Organization::where('id', $organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);
    }

    /**
     * List all templates.
     *
     * @return \Illuminate\Http\Response
     */
    public function listTemplates(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100', // Adjust max per_page limit as needed
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 10);
        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }
        $templates = Template::where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->paginate($perPage, ['uuid', 'name', 'metadata', 'updated_at'], 'page', $page);
        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Templates fetched successfully'),
            'data' => TemplateResource::collection($templates)
        ], 200);
    }
	
    public function listTeamMembers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100', // Adjust max per_page limit as needed
        ]);
		if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 10);
        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }
		$rows = User::join('teams', 'users.id', '=', 'teams.user_id')
		->where('teams.organization_id', $organizationId)
		->whereNull('teams.deleted_at')
		->select('users.*')
		->get()
		->makeHidden(['password','tfa_secret']);
		return response()->json([
			'statusCode' => 200,
			'success' => true,
			'message' => __('Team members fetched successfully'),
			'data' => $rows
		]);
		
    }

    /**
     * Shortcuts available for use in the chat composer (company + current user's personal).
     * Returns empty data when the plan feature is disabled (same as web /shortcuts/available).
     */
    public function listShortcutsAvailable(Request $request)
    {
        $organizationId = (int) $request->user()->current_mobile_organization_id;
        $userId = (int) $request->user()->id;

        if (!SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'shortcuts')) {
            return response()->json([
                'statusCode' => 200,
                'success' => true,
                'message' => __('Request processed successfully', [], getApiLang()),
                'data' => [],
            ], 200);
        }

        $shortcuts = Shortcut::availableFor($organizationId, $userId)
            ->orderBy('command')
            ->get(['uuid', 'command', 'message', 'scope']);

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Request processed successfully', [], getApiLang()),
            'data' => $shortcuts,
        ], 200);
    }

    /**
     * Manageable shortcuts for settings: personal (current user) + company (if privileged).
     */
    public function listShortcuts(Request $request)
    {
        $organizationId = (int) $request->user()->current_mobile_organization_id;
        $userId = (int) $request->user()->id;

        if (!SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'shortcuts')) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('This feature is not available in your plan.', [], getApiLang()),
            ], 403);
        }

        $canManageCompany = $this->canManageCompanyShortcuts($organizationId, $request->user());

        $shortcuts = Shortcut::query()
            ->where('organization_id', $organizationId)
            ->where(function ($q) use ($userId, $canManageCompany) {
                $q->where(function ($p) use ($userId) {
                    $p->where('scope', 'personal')->where('user_id', $userId);
                });
                if ($canManageCompany) {
                    $q->orWhere('scope', 'company');
                }
            })
            ->orderBy('id')
            ->get(['uuid', 'command', 'message', 'scope']);

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Request processed successfully', [], getApiLang()),
            'data' => $shortcuts,
            'can_manage_company' => $canManageCompany,
        ], 200);
    }

    public function storeShortcut(Request $request)
    {
        $organizationId = (int) $request->user()->current_mobile_organization_id;
        $userId = (int) $request->user()->id;

        if (!SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'shortcuts')) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('This feature is not available in your plan.', [], getApiLang()),
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'command' => 'required|string|max:120',
            'message' => 'required|string|max:5000',
            'scope' => 'required|in:personal,company',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => __('The given data was invalid.', [], getApiLang()),
                'errors' => $validator->errors(),
            ], 400);
        }

        $canManageCompany = $this->canManageCompanyShortcuts($organizationId, $request->user());
        $scope = ($request->input('scope') === 'company' && $canManageCompany) ? 'company' : 'personal';
        $command = ltrim(trim((string) $request->input('command')), '/');

        if ($command === '') {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => __('The given data was invalid.', [], getApiLang()),
                'errors' => ['command' => [__('Command name is required.', [], getApiLang())]],
            ], 400);
        }

        $shortcut = Shortcut::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'scope' => $scope,
            'command' => $command,
            'message' => $request->input('message'),
            'created_by' => $userId,
        ]);

        ActivityLogger::log(
            ActivityLogger::SHORTCUT_CREATED,
            $shortcut->command,
            'shortcut',
            $shortcut->id,
            ['scope' => $scope],
            (int) $organizationId
        );

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Request processed successfully', [], getApiLang()),
            'data' => [
                'uuid' => $shortcut->uuid,
                'command' => $shortcut->command,
                'message' => $shortcut->message,
                'scope' => $shortcut->scope,
            ],
        ], 200);
    }

    public function updateShortcut(Request $request, string $uuid)
    {
        $organizationId = (int) $request->user()->current_mobile_organization_id;
        $userId = (int) $request->user()->id;

        if (!SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'shortcuts')) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('This feature is not available in your plan.', [], getApiLang()),
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'command' => 'required|string|max:120',
            'message' => 'required|string|max:5000',
            'scope' => 'required|in:personal,company',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => __('The given data was invalid.', [], getApiLang()),
                'errors' => $validator->errors(),
            ], 400);
        }

        $shortcut = Shortcut::where('organization_id', $organizationId)
            ->where('uuid', $uuid)
            ->first();

        if (!$shortcut) {
            return response()->json([
                'statusCode' => 404,
                'success' => false,
                'message' => __('Not found.', [], getApiLang()),
            ], 404);
        }

        $canManageCompany = $this->canManageCompanyShortcuts($organizationId, $request->user());

        if ($shortcut->scope === 'company' && !$canManageCompany) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('You are not allowed to manage company shortcuts.', [], getApiLang()),
            ], 403);
        }

        if ($shortcut->scope === 'personal' && (int) $shortcut->user_id !== $userId) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('You are not allowed to manage this shortcut.', [], getApiLang()),
            ], 403);
        }

        $scope = ($request->input('scope') === 'company' && $canManageCompany) ? 'company' : 'personal';
        $command = ltrim(trim((string) $request->input('command')), '/');

        if ($command === '') {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => __('The given data was invalid.', [], getApiLang()),
                'errors' => ['command' => [__('Command name is required.', [], getApiLang())]],
            ], 400);
        }

        $shortcut->update([
            'command' => $command,
            'message' => $request->input('message'),
            'scope' => $scope,
        ]);

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Request processed successfully', [], getApiLang()),
            'data' => [
                'uuid' => $shortcut->uuid,
                'command' => $shortcut->command,
                'message' => $shortcut->message,
                'scope' => $shortcut->scope,
            ],
        ], 200);
    }

    public function destroyShortcut(Request $request, string $uuid)
    {
        $organizationId = (int) $request->user()->current_mobile_organization_id;
        $userId = (int) $request->user()->id;

        if (!SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'shortcuts')) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('This feature is not available in your plan.', [], getApiLang()),
            ], 403);
        }

        $shortcut = Shortcut::where('organization_id', $organizationId)
            ->where('uuid', $uuid)
            ->first();

        if (!$shortcut) {
            return response()->json([
                'statusCode' => 404,
                'success' => false,
                'message' => __('Not found.', [], getApiLang()),
            ], 404);
        }

        $canManageCompany = $this->canManageCompanyShortcuts($organizationId, $request->user());

        if ($shortcut->scope === 'company' && !$canManageCompany) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('You are not allowed to manage company shortcuts.', [], getApiLang()),
            ], 403);
        }

        if ($shortcut->scope === 'personal' && (int) $shortcut->user_id !== $userId) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('You are not allowed to manage this shortcut.', [], getApiLang()),
            ], 403);
        }

        ActivityLogger::log(
            ActivityLogger::SHORTCUT_DELETED,
            $shortcut->command ?? null,
            'shortcut',
            $shortcut->id ?? null,
            [],
            (int) $organizationId
        );
        $shortcut->delete();

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Request processed successfully', [], getApiLang()),
            'data' => [],
        ], 200);
    }

    private function canManageCompanyShortcuts(int $organizationId, $user): bool
    {
        $role = $user->getRoleNameForOrganization($organizationId);
        if ($role === '') {
            $role = OrganizationRole::OWNER;
        }

        return OrganizationRole::isPrivileged($role);
    }

    /**
     * * دي اخر الكونتاكتس اللي بعتت رسايل
     * * بحيث اول ما بتدخل علي صفحه الشات دي اول ناس بتظهرلك
     *
     */
    public function listChatContacts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100', // Adjust max per_page limit as needed
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 10);
        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }
        return  (new ChatService($organizationId))->getChatList($request);
        // return response()->json([
        // 	'statusCode' => 200,
        // 	'success' => true,
        // 	'message' => __('Templates fetched successfully'),
        // 	'data' => TemplateResource::collection($templates)
        // ], 200);
    }
    /**
     * *
     * * هنا بنجيب الرسايل الخاصة بجهه اتصال معينه
     */
    // public function listChatContactsForContact(Request $request,$contactUuid)
    // {
    // 	$organizationId = $request->organization;
    // 	if( $request->is('api/v1/*')){
    // 		$organizationId = $request->user()->current_mobile_organization_id;
    // 	}
    //     $validator = Validator::make($request->all(), [
    //         'page' => 'integer|min:1',
    //         'per_page' => 'integer|min:1|max:100', // Adjust max per_page limit as needed
    //     ]);
    // 	$uuid = $contactUuid;
    // 	$contact = Contact::where('uuid', $uuid)->where('organization_id', $organizationId)->first();
    // 	if(!$contact){
    // 		return response()->json([
    // 			'statusCode' => 404,
    // 			'success' => false,
    // 			'message' => __('Contact not found'),
    // 		], 404);
    // 	}
    //     if ($validator->fails()) {
    //         return response()->json(['error' => $validator->errors()], 400);
    //     }

    //     $page = $request->input('page', 1);
    //     $perPage = $request->input('per_page', 10);
        
    //    return  (new ChatService($organizationId))->getChatMessages( $contact->id,$page,$perPage);
        
    // }
    public function listChatMessagesFromUuidToEnd(Request $request)
    {
        set_time_limit(120);

        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }
        $validator = Validator::make($request->all(), [
            'created_at' => 'sometimes|max:255',
            'message_types' => 'sometimes|array|in:chat,ticket,notes',
            'search' => 'sometimes|string|max:255',
            'per_page' => 'sometimes|integer|min:1|max:500',
            'page' => 'sometimes|integer|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $entityTypes = $request->input('message_types', []);
        $createdAt = $request->input('created_at', null);
        $searchTerm = $request->filled('search') ? (string) $request->input('search') : null;
        $orgId = (int) $organizationId;

        // ترقيم اختياري على جهات الاتصال. بدونه يُسلسل الردّ محادثات المنشأة
        // كاملةً في استجابة واحدة — قِسناها على منشأة بثلاثة آلاف محادثة فبلغت
        // 13 ميغابايت و175 ميغابايت ذاكرة، وهو ما يتجاوز مهلة الخادم أو حدّ
        // الذاكرة فيموت الطلب قبل PHP فلا يظهر في السجلّ. الغياب يُبقي السلوك
        // القديم كما هو حتى يتحوّل التطبيق.
        $perPage = $request->filled('per_page') ? (int) $request->input('per_page') : null;
        $page = max(1, (int) $request->input('page', 1));

        $data = $this->loadChatSyncData($orgId, $createdAt, $entityTypes, $perPage, $page);
        $pagination = $data['pagination'];

        if ($data['contactsById']->isEmpty()) {
            return response()->json(array_filter([
                'statusCode' => 200,
                'success' => true,
                'message' => __('Chat messages fetched successfully'),
                'data' => [],
                'pagination' => $pagination,
            ], fn ($value) => $value !== null), 200);
        }

        $contactsById      = $data['contactsById'];
        $chatsMap          = $data['chatsMap'];
        $ticketLogsMap     = $data['ticketLogsMap'];
        $notesMap          = $data['notesMap'];
        $chatLogsByContact = $data['logsByContact'];
        $isDev = config('app.env') === 'development';

        $results = [];
        foreach ($contactsById as $contactId => $contact) {
            $logs = $chatLogsByContact->get($contactId);
            if (!$logs || $logs->isEmpty()) {
                continue;
            }

            $formattedPhone = $contact->formatted_phone;
            if ($formattedPhone === null || $formattedPhone === '') {
                $formattedPhone = $contact->formatted_phone_number;
            }

            $fullName = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
            if (strlen($fullName) > 120) {
                $fullName = mb_strcut($fullName, 0, 120, 'UTF-8');
            }

            $messages = [];
            foreach ($logs as $chatLog) {
                $value = null;
                if ($chatLog->entity_type === 'chat') {
                    $chat = $chatsMap->get($chatLog->entity_id);
                    if (!$chat) continue;
                    if ($isDev) {
                        $meta = is_string($chat->metadata) ? json_decode($chat->metadata, true) : $chat->metadata;
                        if (isset($meta['buttons']) || isset($meta['context'])) continue;
                    }
                    $value = $this->formatChatValue($chat, $contact, $formattedPhone, $fullName);
                } elseif ($chatLog->entity_type === 'ticket') {
                    $value = $ticketLogsMap->get($chatLog->entity_id);
                } elseif ($chatLog->entity_type === 'notes') {
                    $value = $notesMap->get($chatLog->entity_id);
                }
                $messages[] = ['type' => $chatLog->entity_type, 'value' => $value];
            }

            if (empty($messages)) continue;

            $ticket = $contact->ticket;
            $results[] = [
                'contact_id' => $contact->id,
                'last_inbound_chat_created_at' => $contact->last_inbound_chat_created_at,
                'is_blocked' => $contact->is_blocked,
                'ticket_status' => $ticket ? $ticket->status : null,
                'ticket_assigned_to' => $ticket ? $ticket->assigned_to : null,
                'unread_messages_count' => $contact->unread_messages_count,
                'contact_categories' => $contact->relationLoaded('contactCategories')
                    ? $contact->contactCategories->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'background_color' => $c->background_color ?? '#22c55e',
                        'text_color' => $c->text_color ?? '#ffffff',
                    ])->values()->all()
                    : [],
                'messages' => $messages,
            ];
        }

        $payload = array_filter([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Chat messages fetched successfully'),
            'data' => $results,
            'pagination' => $pagination,
        ], fn ($value) => $value !== null);

        $json = JsonText::encode($payload);

        // ردّ ضخم لا يفشل هنا بل عند الخادم أو التطبيق، فلا يترك أثراً في
        // السجلّ. نُسجّله بأنفسنا كي يُعرف السبب بدل «خطأ ما» عند العميل.
        $megabytes = strlen($json) / 1048576;
        if ($megabytes > (float) config('chat.large_sync_warn_mb', 4)) {
            Log::warning('Chat sync response is large', [
                'organization_id' => $orgId,
                'megabytes' => round($megabytes, 2),
                'contacts' => count($results),
                'paginated' => $pagination !== null,
                'created_at' => $createdAt,
            ]);
        }

        // نُرجع النصّ المُرمَّز أعلاه بدل تمرير المصفوفة لـ response()->json،
        // فذاك يُرمّزها مرّة ثانية — نسخة كاملة أخرى من الردّ في الذاكرة بلا
        // فائدة، وبرايات افتراضية تُعيد تهريب العربية التي وفّرناها للتوّ.
        return JsonResponse::fromJsonString($json, 200);
    }

    /**
     * النسخة الثانية من مزامنة المحادثات: نفس البيانات، بلا تكرار جهة الاتصال.
     *
     * في v1 تُكرَّر حقول جهة الاتصال — المعرّف والـ uuid والهاتف والاسم وعدّاد
     * غير المقروء وغيرها — داخل كل رسالة من المحادثة الواحدة. محادثة فيها مئتا
     * رسالة تحمل الاسم والهاتف مئتي مرّة. قِسنا الهدر على المنشأة 211 فبلغ
     * نحو خُمس الردّ.
     *
     * هنا تظهر جهة الاتصال مرّة واحدة في كائن contact، وتحمل الرسالة ما يخصّها
     * وحدها. القيم نفسها والترتيب نفسه — التغيير في موضع الحقل لا في محتواه.
     *
     * v1 باقٍ كما هو حتى ينتقل التطبيق.
     */
    public function listChatMessagesFromUuidToEndV2(Request $request)
    {
        set_time_limit(120);

        $organizationId = $request->organization;
        if ($request->is('api/v1/*')) {
            $organizationId = $request->user()->current_mobile_organization_id;
        }
        $validator = Validator::make($request->all(), [
            'created_at' => 'sometimes|max:255',
            'message_types' => 'sometimes|array|in:chat,ticket,notes',
            'search' => 'sometimes|string|max:255',
            'per_page' => 'sometimes|integer|min:1|max:500',
            'page' => 'sometimes|integer|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $entityTypes = $request->input('message_types', []);
        $createdAt = $request->input('created_at', null);
        $orgId = (int) $organizationId;
        $perPage = $request->filled('per_page') ? (int) $request->input('per_page') : null;
        $page = max(1, (int) $request->input('page', 1));

        $data = $this->loadChatSyncData($orgId, $createdAt, $entityTypes, $perPage, $page);
        $pagination = $data['pagination'];

        if ($data['contactsById']->isEmpty()) {
            return response()->json(array_filter([
                'statusCode' => 200,
                'success' => true,
                'message' => __('Chat messages fetched successfully'),
                'data' => [],
                'pagination' => $pagination,
            ], fn ($value) => $value !== null), 200);
        }

        $chatsMap      = $data['chatsMap'];
        $ticketLogsMap = $data['ticketLogsMap'];
        $notesMap      = $data['notesMap'];
        $isDev = config('app.env') === 'development';

        $results = [];
        foreach ($data['contactsById'] as $contactId => $contact) {
            $logs = $data['logsByContact']->get($contactId);
            if (!$logs || $logs->isEmpty()) {
                continue;
            }

            $messages = [];
            foreach ($logs as $chatLog) {
                $value = null;
                if ($chatLog->entity_type === 'chat') {
                    $chat = $chatsMap->get($chatLog->entity_id);
                    if (!$chat) continue;
                    if ($isDev) {
                        $meta = is_string($chat->metadata) ? json_decode($chat->metadata, true) : $chat->metadata;
                        if (isset($meta['buttons']) || isset($meta['context'])) continue;
                    }
                    $value = $this->formatChatMessageV2($chat);
                } elseif ($chatLog->entity_type === 'ticket') {
                    $value = $ticketLogsMap->get($chatLog->entity_id);
                } elseif ($chatLog->entity_type === 'notes') {
                    $value = $notesMap->get($chatLog->entity_id);
                }
                $messages[] = ['type' => $chatLog->entity_type, 'value' => $value];
            }

            if (empty($messages)) continue;

            $formattedPhone = $contact->formatted_phone;
            if ($formattedPhone === null || $formattedPhone === '') {
                $formattedPhone = $contact->formatted_phone_number;
            }

            $fullName = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
            if (strlen($fullName) > 120) {
                $fullName = mb_strcut($fullName, 0, 120, 'UTF-8');
            }

            $ticket = $contact->ticket;
            $results[] = [
                'contact' => [
                    'contact_id' => $contact->id,
                    'contact_uuid' => $contact->uuid,
                    'phone' => $contact->phone,
                    'formatted_phone_number' => $formattedPhone,
                    'contact_full_name' => $fullName ?: null,
                    'organization_id' => $contact->organization_id,
                    'latest_chat_created_at' => $contact->latest_chat_created_at,
                    'last_inbound_chat_created_at' => $contact->last_inbound_chat_created_at,
                    'is_blocked' => $contact->is_blocked,
                    'is_favorite' => $contact->is_favorite,
                    'unread_messages_count' => (int) ($contact->unread_messages_count ?? 0),
                    'ticket_status' => $ticket ? $ticket->status : null,
                    'ticket_assigned_to' => $ticket ? $ticket->assigned_to : null,
                    'contact_categories' => $contact->relationLoaded('contactCategories')
                        ? $contact->contactCategories->map(fn ($c) => [
                            'id' => $c->id,
                            'name' => $c->name,
                            'background_color' => $c->background_color ?? '#22c55e',
                            'text_color' => $c->text_color ?? '#ffffff',
                        ])->values()->all()
                        : [],
                ],
                'messages' => $messages,
            ];
        }

        $payload = array_filter([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Chat messages fetched successfully'),
            'data' => $results,
            'pagination' => $pagination,
        ], fn ($value) => $value !== null);

        $json = JsonText::encode($payload);

        $megabytes = strlen($json) / 1048576;
        if ($megabytes > (float) config('chat.large_sync_warn_mb', 4)) {
            Log::warning('Chat sync response is large', [
                'organization_id' => $orgId,
                'megabytes' => round($megabytes, 2),
                'contacts' => count($results),
                'paginated' => $pagination !== null,
                'created_at' => $createdAt,
                'version' => 'v2',
            ]);
        }

        return JsonResponse::fromJsonString($json, 200);
    }

    /**
     * تنسيق رسالة واحدة لـ v2: ما يخصّ الرسالة وحدها. حقول جهة الاتصال
     * تخرج مرّة واحدة في كائن contact فوقها، فلا تُكرَّر هنا.
     *
     * أُسقط is_new_contact: قيمته في هذا الـ endpoint false دائماً — لا خبر فيه.
     */
    private function formatChatMessageV2($chat): array
    {
        $arr = $chat instanceof \Illuminate\Database\Eloquent\Model ? $chat->toArray() : (array) $chat;

        $user = null;
            // اسم المُرسِل كاملاً وحده: التطبيق يعرضه كما هو ولا يُركّب شيئاً،
            // فالحقلان المنفصلان لا مستهلك لهما ووجودهما يُغري بتركيب مختلف
            // عمّا يعرضه الداشبورد.
        if (!empty($arr['user']) && is_array($arr['user'])) {
            $user = [
                'full_name' => trim((string) ($arr['user']['full_name']
                    ?? (($arr['user']['first_name'] ?? '') . ' ' . ($arr['user']['last_name'] ?? '')))),
            ];
        }

        $media = null;
        if (!empty($arr['media']) && is_array($arr['media'])) {
            $media = [
                'type' => $arr['media']['type'] ?? null,
                'size' => $arr['media']['size'] ?? null,
                'path' => mb_strcut($arr['media']['path'] ?? '', 0, 200, 'UTF-8'),
                'name' => mb_strcut($arr['media']['name'] ?? '', 0, 80, 'UTF-8'),
            ];
        }

        $rawLogs = $arr['logs'] ?? [];
        $logs = [];
        if (!empty($rawLogs) && is_array($rawLogs)) {
            $rawLogs = array_slice($rawLogs, -6, 6);
            foreach ($rawLogs as $log) {
                $logArr = is_array($log) ? $log : (array) $log;
                $rawMetadata = $logArr['metadata'] ?? '{}';
                $decoded = is_string($rawMetadata) ? json_decode($rawMetadata, true) : $rawMetadata;
                if (!is_array($decoded)) {
                    $decoded = [];
                }
                $minimal = array_intersect_key($decoded, array_flip(['status', 'errors', 'id']));
                $minimal = ChatStatus::normalizeLogMetadata($minimal);
                $logs[] = ['metadata' => JsonText::encode($minimal)];
            }
        }

        $metadata = $arr['metadata'] ?? null;
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $type = $metadata['type'] ?? null;
        if (is_array($metadata) && isset($metadata['type']) && $type && empty($metadata[$type])) {
            $metadata[$type] = null;
        }
        if (is_array($metadata)) {
            $metadata = JsonText::encode($metadata);
        }

        return [
            'id' => $arr['id'] ?? null,
            'uuid' => $arr['uuid'] ?? null,
            'created_at' => $arr['created_at'] ?? null,
            'deleted_at' => $arr['deleted_at'] ?? null,
            'metadata' => $metadata,
            'type' => $arr['type'] ?? 'outbound',
            'wam_id' => $arr['wam_id'] ?? null,
            // نُخرج فقط الحالات التي يفهمها التطبيق؛ `played` تُترجم إلى `read`.
            'status' => ChatStatus::forApi($arr['status'] ?? null),
            'media' => $media,
            'logs' => $logs,
            'user' => $user,
        ];
    }

    /**
     * تسجيل حدث من مسار التطبيق. مسارات API لا تملك جلسة، فالمنظمة تُؤخذ من
     * عمود المستخدم صراحةً بدل الاعتماد على الاستنتاج.
     */
    private function logMobileActivity(string $event, $contact = null, array $properties = [], ?int $organizationId = null): void
    {
        // كل شيء داخل try — بما فيه بناء الاسم. ActivityLogger::log يحمي نفسه،
        // لكن بناء الوسائط كان يقع خارج حمايته، فرمى استدعاءُ اسمٍ غير مُحمَّل
        // استثناءً أسقط /api/send/template في الإنتاج. التسجيل خدمةٌ للعملية،
        // ولا يجوز بحال أن يُفشل إرسال رسالة إلى عميل.
        try {
            $label = null;
            if ($contact) {
                $label = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $contact->phone;
            }

            ActivityLogger::log(
                $event,
                $label,
                $contact ? 'contact' : null,
                $contact->id ?? null,
                $properties,
                (int) ($organizationId ?? request()->user()?->current_mobile_organization_id ?? 0) ?: null
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('logMobileActivity failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * طلب موقع العميل من التطبيق.
     */
    public function requestLocation(Request $request)
    {
        $organizationId = (int) ($request->user()?->current_mobile_organization_id ?: $request->organization);

        $validator = Validator::make($request->all(), [
            'uuid' => 'required|string',
            'body' => 'required|string|max:' . WhatsappService::LOCATION_REQUEST_MAX_BODY,
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();

            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => $errors->first(),
                'errors' => $errors,
            ], 400);
        }

        // نفحص النافذة هنا لا في الخدمة: مسارات الإرسال الأخرى في هذا المتحكّم
        // تُرجع شكل الـAPI الحامل لـstatusCode (السطران 972 و1276)، وتطبيق
        // الجوال يقرؤه. تركُ ردّ الويب يمرّ كان سيُخرج شكلاً مختلفاً لنفس الخطأ.
        $contact = Contact::where('uuid', (string) $request->input('uuid'))
            ->where('organization_id', $organizationId)
            ->first();

        if ($contact && !MessagingWindowHelper::isMessagingWindowOpen($contact)) {
            return MessagingWindowHelper::closedWindowApiJsonResponse();
        }

        return (new ChatService($organizationId))->requestLocation(
            (string) $request->input('uuid'),
            (string) $request->input('body'),
            (int) $request->user()->id
        );
    }

    /**
     * إرسال موقع النشاط التجاري إلى العميل من تطبيق الجوال.
     *
     * نظير ChatController::sendLocation، بشكل ردٍّ حامل لـstatusCode كبقية
     * مسارات هذا المتحكّم — تطبيق الجوال يقرؤه ويفرّع عليه.
     */
    public function sendLocation(Request $request)
    {
        $organizationId = (int) ($request->user()?->current_mobile_organization_id ?: $request->organization);
        $useOrganizationLocation = $request->boolean('use_organization_location');

        $validator = Validator::make($request->all(), [
            'uuid' => 'required|string',
            'use_organization_location' => 'sometimes|boolean',
            'latitude' => ($useOrganizationLocation ? 'nullable' : 'required') . '|numeric|between:-90,90',
            'longitude' => ($useOrganizationLocation ? 'nullable' : 'required') . '|numeric|between:-180,180',
            'name' => 'nullable|string|max:' . WhatsappService::LOCATION_NAME_MAX,
            'address' => 'nullable|string|max:' . WhatsappService::LOCATION_ADDRESS_MAX,
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();

            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => $errors->first(),
                'errors' => $errors,
            ], 400);
        }

        // نفحص النافذة هنا لا في الخدمة: ردّ الخدمة بشكل الويب، وتطبيق الجوال
        // يتوقّع statusCode — نفس علّة requestLocation أعلاه.
        $contact = Contact::where('uuid', (string) $request->input('uuid'))
            ->where('organization_id', $organizationId)
            ->first();

        if ($contact && !MessagingWindowHelper::isMessagingWindowOpen($contact)) {
            return MessagingWindowHelper::closedWindowApiJsonResponse();
        }

        $service = new ChatService($organizationId);

        if ($useOrganizationLocation) {
            $location = $service->getOrganizationLocation();

            if ($location === null) {
                return response()->json([
                    'statusCode' => 422,
                    'success' => false,
                    'message' => __('Your business location is not set yet. Add it in Settings first.'),
                ], 422);
            }
        } else {
            $location = [
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'name' => $request->input('name'),
                'address' => $request->input('address'),
            ];
        }

        return $service->sendLocation(
            (string) $request->input('uuid'),
            $location,
            (int) $request->user()->id
        );
    }

    /**
     * موقع النشاط التجاري المحفوظ — يملأ به التطبيق خيار «أرسل عنواننا»
     * ويُخفيه إن لم يُضبط بعد بدل أن يُرسل نقطةً فارغة.
     */
    public function organizationLocation(Request $request)
    {
        $organizationId = (int) ($request->user()?->current_mobile_organization_id ?: $request->organization);

        $location = (new ChatService($organizationId))->getOrganizationLocation();

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'data' => $location,
            'is_set' => $location !== null,
        ]);
    }

    /**
     * نبضة نشاط من التطبيق. لم يكن للموبايل نبضة إطلاقاً — النبضة الوحيدة في
     * routes/web.php — فكل من يعمل من التطبيق يظهر «غير متصل» دائماً مهما أرسل.
     */
    public function performanceHeartbeat(Request $request)
    {
        $organizationId = (int) $request->user()->current_mobile_organization_id;

        if ($organizationId && SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'agent_performance')) {
            AgentPerformanceService::recordHeartbeat(
                $organizationId,
                (int) $request->user()->id,
                $request->boolean('visible', true)
            );
        }

        return response()->json(['success' => true]);
    }

    /**
     * تحميل ما تحتاجه مزامنة المحادثات — جهات الاتصال وسجلّاتها والكيانات
     * المرتبطة — بلا أي بناء للردّ. النسختان v1 و v2 تختلفان في شكل الردّ
     * وحده، فالاستعلامات هنا مشتركة كي لا يتفرّع سلوكهما.
     *
     * @return array{contactsById: \Illuminate\Support\Collection, logsByContact: \Illuminate\Support\Collection, chatsMap: \Illuminate\Support\Collection, ticketLogsMap: \Illuminate\Support\Collection, notesMap: \Illuminate\Support\Collection, pagination: ?array}
     */
    private function loadChatSyncData(int $orgId, ?string $createdAt, array $entityTypes, ?int $perPage, int $page): array
    {
        // Step 1: Load only contacts that have matching chat_logs (skip empty contacts)
        $contactsQuery = Contact::where('organization_id', $orgId)
            ->with([
                'contactCategories:id,name,uuid,background_color,text_color',
                'ticket:id,status,assigned_to,contact_id',
            ])
            ->addSelect(
                'contacts.*',
                DB::raw(
                    '(SELECT COUNT(*) FROM chats
                      WHERE chats.contact_id = contacts.id
                      AND chats.type = \'inbound\'
                      AND chats.is_read = 0
                      AND chats.organization_id = ' . $orgId . '
                      AND chats.deleted_at IS NULL) as unread_messages_count'
                )
            )
            ->whereExists(function ($sub) use ($createdAt, $entityTypes) {
                $sub->select(DB::raw(1))
                    ->from('chat_logs')
                    ->whereColumn('chat_logs.contact_id', 'contacts.id')
                    ->whereNull('chat_logs.deleted_at');
                if ($createdAt) {
                    $sub->where('chat_logs.created_at', '>=', $createdAt);
                }
                if (!empty($entityTypes)) {
                    $sub->whereIn('chat_logs.entity_type', $entityTypes);
                }
            });

        $contactsQuery->orderBy('latest_chat_created_at', 'desc');

        $pagination = null;
        if ($perPage !== null) {
            $totalContacts = (clone $contactsQuery)->toBase()->getCountForPagination();
            $contacts = $contactsQuery->forPage($page, $perPage)->get();
            $pagination = [
                'page' => $page,
                'per_page' => $perPage,
                'total_contacts' => $totalContacts,
                'last_page' => (int) max(1, ceil($totalContacts / $perPage)),
            ];
        } else {
            $contacts = $contactsQuery->get();
        }

        $empty = [
            'contactsById' => collect(),
            'logsByContact' => collect(),
            'chatsMap' => collect(),
            'ticketLogsMap' => collect(),
            'notesMap' => collect(),
            'pagination' => $pagination,
        ];

        if ($contacts->isEmpty()) {
            return $empty;
        }

        $contactIds = $contacts->pluck('id')->all();

        // Step 2: Load chat_logs in chunks (avoid MySQL "too many placeholders" on large orgs)
        $allChatLogs = $this->getModelsByIdsInChunks(
            ChatLog::query()
                ->whereNull('deleted_at')
                ->when($createdAt, fn ($q) => $q->where('created_at', '>=', $createdAt))
                ->when(!empty($entityTypes), fn ($q) => $q->whereIn('entity_type', $entityTypes))
                ->orderBy('created_at', 'asc'),
            'contact_id',
            $contactIds
        );

        // Step 3: Batch-load related entities in chunks (MySQL placeholder limit is ~65535)
        $chatIds    = $allChatLogs->where('entity_type', 'chat')->pluck('entity_id')->unique()->filter()->values()->all();
        $ticketIds  = $allChatLogs->where('entity_type', 'ticket')->pluck('entity_id')->unique()->filter()->values()->all();
        $noteIds    = $allChatLogs->where('entity_type', 'notes')->pluck('entity_id')->unique()->filter()->values()->all();

        return [
            'contactsById' => $contacts->keyBy('id'),
            'logsByContact' => $allChatLogs->groupBy('contact_id'),
            'chatsMap' => $this->getModelsByIdsInChunks(
                $this->visibleChatsQuery()->with('media', 'user', 'logs'),
                'id',
                $chatIds
            )->keyBy('id'),
            'ticketLogsMap' => $this->getModelsByIdsInChunks(ChatTicketLog::query(), 'id', $ticketIds)->keyBy('id'),
            'notesMap' => $this->getModelsByIdsInChunks(ChatNote::query(), 'id', $noteIds)->keyBy('id'),
            'pagination' => $pagination,
        ];
    }

    /**
     * استعلام الرسائل الذاهبة إلى تطبيق الجوال، بلا التفاعلات بالإيموجي.
     *
     * التفاعل يصل صفّاً مستقلاً بلا فرع عرض في التطبيق، فيظهر فقاعةً فارغة —
     * نفس ما كان يقع في الداشبورد قبل ترشيحه هناك. ولأن التطبيق يشتقّ «آخر
     * رسالة» في قائمة المحادثات من هذه المصفوفة نفسها، فترشيحه هنا يُصلح
     * السطر الفارغ في القائمة أيضاً.
     *
     * مطابقة نصّية لا دوالّ JSON: JSON_EXTRACT على صفّ تالف تُوقف الاستعلام
     * بخطأ وتُسقط المحادثة كلّها.
     */
    private function visibleChatsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Chat::query()->where('metadata', 'not like', '%"type":"reaction"%');
    }

    /**
     * Load models with whereIn in chunks to stay under MySQL's prepared-statement
     * placeholder limit (~65535). Preserves the base query's with()/where()/orderBy.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $baseQuery
     * @param  string  $column
     * @param  array<int, mixed>  $ids
     * @param  int  $chunkSize
     * @return \Illuminate\Support\Collection
     */
    private function getModelsByIdsInChunks($baseQuery, string $column, array $ids, int $chunkSize = 1000)
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id !== null && $id !== '')));

        if ($ids === []) {
            return collect();
        }

        $results = collect();

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $results = $results->merge(
                (clone $baseQuery)->whereIn($column, $chunk)->get()
            );
        }

        return $results;
    }

    /**
     * Format a chat record for the mobile API using the already-loaded contact
     * instead of re-querying it from DB (replaces minimalChatValue N+1).
     */
    private function formatChatValue($chat, $contact, ?string $formattedPhone = null, ?string $contactFullName = null): array
    {
        $arr = $chat instanceof \Illuminate\Database\Eloquent\Model ? $chat->toArray() : (array) $chat;

        $user = null;
            // اسم المُرسِل كاملاً وحده: التطبيق يعرضه كما هو ولا يُركّب شيئاً،
            // فالحقلان المنفصلان لا مستهلك لهما ووجودهما يُغري بتركيب مختلف
            // عمّا يعرضه الداشبورد.
        if (!empty($arr['user']) && is_array($arr['user'])) {
            $user = [
                'full_name' => trim((string) ($arr['user']['full_name']
                    ?? (($arr['user']['first_name'] ?? '') . ' ' . ($arr['user']['last_name'] ?? '')))),
            ];
        }

        $media = null;
        if (!empty($arr['media']) && is_array($arr['media'])) {
            $media = [
                'type' => $arr['media']['type'] ?? null,
                'size' => $arr['media']['size'] ?? null,
                'path' => mb_strcut($arr['media']['path'] ?? '', 0, 200, 'UTF-8'),
                'name' => mb_strcut($arr['media']['name'] ?? '', 0, 80, 'UTF-8'),
            ];
        }

        $rawLogs = $arr['logs'] ?? [];
        $logs = [];
        if (!empty($rawLogs) && is_array($rawLogs)) {
            $rawLogs = array_slice($rawLogs, -6, 6);
            foreach ($rawLogs as $log) {
                $logArr = is_array($log) ? $log : (array) $log;
                $rawMetadata = $logArr['metadata'] ?? '{}';
                $decoded = is_string($rawMetadata) ? json_decode($rawMetadata, true) : $rawMetadata;
                if (!is_array($decoded)) {
                    $decoded = [];
                }
                $minimal = array_intersect_key($decoded, array_flip(['status', 'errors', 'id']));
                $minimal = ChatStatus::normalizeLogMetadata($minimal);
                $logs[] = ['metadata' => JsonText::encode($minimal)];
            }
        }

        $metadata = $arr['metadata'] ?? null;
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $type = $metadata['type'] ?? null;
        if (is_array($metadata) && isset($metadata['type']) && $type && empty($metadata[$type])) {
            $metadata[$type] = null;
        }
        if (is_array($metadata)) {
            $metadata = JsonText::encode($metadata);
        }

        $fullName = $contactFullName;
        if ($fullName === null) {
            $fullName = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
            if (strlen($fullName) > 120) {
                $fullName = mb_strcut($fullName, 0, 120, 'UTF-8');
            }
        }

        return [
            'id' => $arr['id'] ?? null,
            'uuid' => $arr['uuid'] ?? null,
            'contact_uuid' => $contact->uuid,
            'contact_id' => $contact->id,
            'is_new_contact' => false,
            'phone' => $contact->phone,
            'formatted_phone_number' => $formattedPhone ?? $contact->formatted_phone_number,
            'organization_id' => $contact->organization_id,
            'latest_chat_created_at' => $contact->latest_chat_created_at,
            'is_blocked' => $contact->is_blocked,
            'is_favorite' => $contact->is_favorite,
            'contact_full_name' => $fullName ?: null,
            'unread_messages_count' => (int) ($contact->unread_messages_count ?? 0),
            'created_at' => $arr['created_at'] ?? null,
            'deleted_at' => $arr['deleted_at'] ?? null,
            'metadata' => $metadata,
            'type' => $arr['type'] ?? 'outbound',
            'wam_id' => $arr['wam_id'] ?? null,
            // نُخرج فقط الحالات التي يفهمها التطبيق؛ `played` تُترجم إلى `read`.
            'status' => ChatStatus::forApi($arr['status'] ?? null),
            'media' => $media,
            'logs' => $logs,
            'user' => $user,
        ];
    }

    protected function getChatMessages($contactId, $createdAt, $entityTypes)
    {
        $query = ChatLog::where('contact_id', $contactId)
            ->where('deleted_at', null)
            ->orderBy('created_at', 'desc')
            ->when($createdAt, function ($q) use ($createdAt) {
                $q->where('created_at', '>=', $createdAt);
            })
            ->when(count($entityTypes), function ($q) use ($entityTypes) {
                $q->whereIn('entity_type', $entityTypes);
            });

        $chatLogs = $query->get() ;
        $chatIds = $chatLogs->where('entity_type', 'chat')->pluck('entity_id')->unique()->filter()->values()->all();
        $ticketIds = $chatLogs->where('entity_type', 'ticket')->pluck('entity_id')->unique()->filter()->values()->all();
        $noteIds = $chatLogs->where('entity_type', 'notes')->pluck('entity_id')->unique()->filter()->values()->all();

        $chatsMap = !empty($chatIds)
            ? $this->visibleChatsQuery()->with('media', 'user', 'logs')->whereIn('id', $chatIds)->get()->keyBy('id')
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

                // رسالة غير موجودة في الخريطة: محذوفة أو مُرشَّحة (تفاعل).
                // الحلقتان الأخريان تتخطّيان هنا، وهذه كانت تمرّر null.
                if (!$value) {
                    continue;
                }

                // temp condition to skip buttons and context for mobile testing now
                if (env('APP_ENV') == 'development') {
                    if (isset($value['metadata']) && (isset(json_decode($value['metadata'], true)['buttons']) || isset(json_decode($value['metadata'], true)['context']))
                    ) {
                        continue;
                    }
                }
                
                $value = minimalChatValue($value);
            } elseif ($chatLog->entity_type === 'ticket') {
                $value = $ticketLogsMap->get($chatLog->entity_id);
            } elseif ($chatLog->entity_type === 'notes') {
                $value = $notesMap->get($chatLog->entity_id);
            }
            $chats[] = [['type' => $chatLog->entity_type, 'value' => $value]];
        }
        return array_reverse($chats) ;
    }
	
	
	// public function listChatMessagesFromUuidToEnd(Request $request)
    // {
    //     $organizationId = $request->organization;
    //     if ($request->is('api/v1/*')) {
    //         $organizationId = $request->user()->current_mobile_organization_id;
    //     }
    //     $validator = Validator::make($request->all(), [
    //         'created_at' => 'sometimes|max:255',
    //         'message_types' => 'sometimes|array|in:chat,ticket,notes',
    //         'search' => 'sometimes|string|max:255',
    //     ]);
    //     if ($validator->fails()) {
    //         return response()->json(['error' => $validator->errors()], 400);
    //     }
    //     $entityTypes = $request->input('message_types', []);
        
    //     $createdAt = $request->input('created_at', null);
    //     // $page = $request->input('page', 1);
    //     // $perPage = $request->input('per_page', 10);
    //     $organization = Organization::where('id', $organizationId)->first();
    //     $orgId = (int) $organizationId;
    //     // $searchTerm = $request->filled('search') ? (string) $request->input('search') : null;

    //     $contacts = $organization->contacts()
    //         ->with('contactCategories:id,name,uuid,background_color,text_color', 'ticket:id,status,assigned_to,contact_id')
    //         ->addSelect(
    //             'contacts.*',
    //             DB::raw(
    //                 '(SELECT COUNT(*) FROM chats
    //               WHERE chats.contact_id = contacts.id
    //               AND chats.type = \'inbound\'
    //               AND chats.is_read = 0
    //               AND chats.organization_id = '.$orgId.'
    //               AND chats.deleted_at IS NULL) as unread_messages_count'
    //             )
    //         )
    //         ->get();
    //     $results = [];
    //     foreach ($contacts as $contact) {
    //         $result = $this->getChatMessages($contact->id, $createdAt, $entityTypes);
    //         $data = [] ;
	// 		$ticket=$contact->ticket;
    //         if ($result) {
    //             $data['contact_id']=$contact->id;
    //             $data['last_inbound_chat_created_at']=$contact->last_inbound_chat_created_at;
	// 			$data['is_blocked']=$contact->is_blocked;
	// 			$data['ticket_status']=$ticket ? $ticket->status : null;
	// 			$data['ticket_assigned_to']=$ticket ? $ticket->assigned_to : null;
	// 			$data['unread_messages_count']  = $contact->unread_messages_count;
    //             $data['contact_categories'] = $contact->relationLoaded('contactCategories')
    //                 ? $contact->contactCategories->map(fn ($c) => [
    //                     'id' => $c->id,
    //                     'name' => $c->name,
    //                     'background_color' => $c->background_color ?? '#22c55e',
    //                     'text_color' => $c->text_color ?? '#ffffff',
    //                 ])->values()->all()
    //                 : [];
    //             foreach ($result as $item) {
    //                 foreach ($item as $item2) {
    //                     $data['messages'][] =$item2;
    //                 }
    //             }
	// 				$results[] = $data;
    //         }
    //     }
	
    //     return response()->json([
    //         'statusCode' => 200,
    //         'success' => true,
    //         'message' => __('Chat messages fetched successfully'),
    //         'data' => $results
    //     ], 200);
    // }
    // protected function getChatMessages($contactId, $createdAt, $entityTypes)
    // {
    //     $query = ChatLog::where('contact_id', $contactId)
    //         ->where('deleted_at', null)
    //         ->orderBy('created_at', 'desc')
    //         ->when($createdAt, function ($q) use ($createdAt) {
    //             $q->where('created_at', '>=', $createdAt);
    //         })
    //         ->when(count($entityTypes), function ($q) use ($entityTypes) {
    //             $q->whereIn('entity_type', $entityTypes);
    //         });

    //     $chatLogs = $query->get() ;
    //     $chatIds = $chatLogs->where('entity_type', 'chat')->pluck('entity_id')->unique()->filter()->values()->all();
    //     $ticketIds = $chatLogs->where('entity_type', 'ticket')->pluck('entity_id')->unique()->filter()->values()->all();
    //     $noteIds = $chatLogs->where('entity_type', 'notes')->pluck('entity_id')->unique()->filter()->values()->all();

    //     $chatsMap = !empty($chatIds)
    //         ? Chat::with('media', 'user', 'logs')->whereIn('id', $chatIds)->get()->keyBy('id')
    //         : collect();
    //     $ticketLogsMap = !empty($ticketIds)
    //         ? ChatTicketLog::whereIn('id', $ticketIds)->get()->keyBy('id')
    //         : collect();
    //     $notesMap = !empty($noteIds)
    //         ? ChatNote::whereIn('id', $noteIds)->get()->keyBy('id')
    //         : collect();

    //     $chats = [];
    //     foreach ($chatLogs as $chatLog) {
    //         $value = null;
    //         if ($chatLog->entity_type === 'chat') {
    //             $value = $chatsMap->get($chatLog->entity_id);
    //             // temp condition to skip buttons and context for mobile testing now
    //             if (env('APP_ENV') == 'development') {
    //                 if (isset($value['metadata']) && (isset(json_decode($value['metadata'], true)['buttons']) || isset(json_decode($value['metadata'], true)['context']))
    //                 ) {
    //                     continue;
    //                 }
    //             }
                
    //             $value = minimalChatValue($value);
    //         } elseif ($chatLog->entity_type === 'ticket') {
    //             $value = $ticketLogsMap->get($chatLog->entity_id);
    //         } elseif ($chatLog->entity_type === 'notes') {
    //             $value = $notesMap->get($chatLog->entity_id);
    //         }
    //         $chats[] = [['type' => $chatLog->entity_type, 'value' => $value]];
    //     }
    //     return array_reverse($chats) ;
    // }
	
    public function deleteChatForContact(Request $request, $uuid)
    {
        $organizationId = $request->user()->current_mobile_organization_id;
        $contact = Contact::where('uuid', $uuid)->where('organization_id', $organizationId)->first();
        if (!$contact) {
            return response()->json([
                'statusCode' => 404,
                'success' => false,
                'message' => __('Contact not found'),
            ], 404);
        }
        
        $chatService = new ChatService($organizationId);
        $chatService->clearContactChat($uuid);
        $this->logMobileActivity(ActivityLogger::CHAT_DELETED, $contact, [], (int) $organizationId);
        
        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Chat deleted successfully'),
        ], 200);
    }
    public function toggleTicketStatus(Request $request, $id)
    {
        // $organizationId = $request->user()->current_mobile_organization_id;
        $contact = Contact::find($id);
        if (!$contact) {
            return response()->json([
                'statusCode' => 404,
                'success' => false,
                'message' => __('Contact not found'),
            ], 404);
        }
        
        $contact->toggleTicketStatus($request->status);
        $this->logMobileActivity(
            $request->status === 'closed' ? ActivityLogger::TICKET_CLOSED : ActivityLogger::TICKET_REOPENED,
            $contact,
            ['status' => $request->status],
            (int) $contact->organization_id
        );

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Status updated successfully!'),
        ], 200);
    }
    
    /**
     * Verify if the API key is active.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function verifyApiKey(Request $request)
    {
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return response()->json([
                'statusCode' => 401,
                'message' => __('No API key provided. Please include it in the Authorization header as a Bearer token.')
            ], 401);
        }

        try {
            $token = DB::table('organization_api_keys')
                ->where('token', $bearerToken)
                ->whereNull('deleted_at')
                ->first();

            if (!$token) {
                return response()->json([
                    'statusCode' => 401,
                    'message' => __('Invalid API key.')
                ], 401);
            }

            $organizationId = $token->organization_id;

            if (!SubscriptionService::isSubscriptionActive($organizationId)) {
                return response()->json([
                    'statusCode' => 403,
                    'message' => __('API key is inactive. Please renew or subscribe to a plan to continue!')
                ], 403);
            }

            return response()->json([
                'statusCode' => 200,
                'message' => __('API key is valid and active')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'statusCode' => 500,
                'message' => __('Request unable to be processed')
            ], 500);
        }
    }

    /**
     * Return a temporary signed URL for chat media stored on S3 so the mobile app can load it without AWS credentials.
     * Mobile sends only the app Bearer token; the backend uses AWS to generate a signed URL valid for a short time.
     *
     * GET /api/v1/media/signed-url?chat_id=123
     */
    public function getSignedMediaUrl(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'chat_id' => 'required|integer|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $organizationId = $request->user()->current_mobile_organization_id;
        $chat = Chat::with('media')->where('id', $request->chat_id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$chat || !$chat->media_id) {
            return response()->json([
                'statusCode' => 404,
                'success' => false,
                'message' => __('Chat or media not found'),
            ], 404);
        }

        $media = $chat->media;
        $minutes = 60;

        if ($media->location === 'amazon' && $media->path) {
            $path = $media->path;
            $key = ltrim(parse_url($path, PHP_URL_PATH) ?? '', '/');
            if ($key === '') {
                return response()->json([
                    'statusCode' => 400,
                    'success' => false,
                    'message' => __('Invalid media path'),
                ], 400);
            }
            try {
                $signedUrl = Storage::disk('s3')->temporaryUrl($key, now()->addMinutes($minutes));
                return response()->json([
                    'statusCode' => 200,
                    'success' => true,
                    'url' => $signedUrl,
                    'expires_in_seconds' => $minutes * 60,
                ], 200);
            } catch (\Throwable $e) {
                return response()->json([
                    'statusCode' => 500,
                    'success' => false,
                    'message' => __('Failed to generate media URL'),
                ], 500);
            }
        }

        // Local storage: return the existing public URL as-is
        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'url' => $media->path,
            'expires_in_seconds' => null,
        ], 200);
    }
	public function assignContactToUserThroughTicket(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'contact_id' => 'required|integer|min:1',
			'user_id' => 'required|integer|min:1',
		]);
		if ($validator->fails()) {
			return response()->json(['error' => $validator->errors()], 400);
		}
		$contact = Contact::where('id', $request->contact_id)->first();
		
		if (!$contact) {
			return response()->json([
				'statusCode' => 404,
				'success' => false,
				'message' => __('Contact not found'),
			], 404);
		}
		$user = User::find($request->user_id);
		if (!$user) {
			return response()->json([
				'statusCode' => 404,
				'success' => false,
				'message' => __('User not found'),
			], 404);
		}
		$contact->assignToUserThroughTicket($user);
		$this->logMobileActivity(
			ActivityLogger::TICKET_ASSIGNED,
			$contact,
			['assigned_to' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email],
			(int) $contact->organization_id
		);
		return response()->json([
			'statusCode' => 200,
			'success' => true,
			'message' => __('Contact assigned to user successfully'),
		], 200);
	}
	public function markAsRead(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'contact_id' => 'required|integer|min:1',
		]);
		if ($validator->fails()) {
			return response()->json(['error' => $validator->errors()], 400);
		}
		$contact = Contact::find($request->contact_id);
		if (!$contact) {
			return response()->json([
				'statusCode' => 404,
				'success' => false,
				'message' => __('Contact not found'),
			], 404);
		}
		DB::table('chats')->where('contact_id', $contact->id)
			->where('type', 'inbound')
			->whereNull('deleted_at')
			->where('is_read', 0)
			->update(['is_read' => 1]);
			
		return response()->json([
			'statusCode' => 200,
			'success' => true,
			'message' => __('Chat count reset successfully'),
		], 200);
	}
}
