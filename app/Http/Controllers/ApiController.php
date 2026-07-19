<?php

namespace App\Http\Controllers;

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
use App\Models\Template;
use App\Models\User;
use App\Services\ChatService;
use App\Services\ContactService;
use App\Services\MediaService;
use App\Services\PhoneService;
use App\Services\SubscriptionService;
use App\Services\WhatsappService;
use App\Traits\TemplateTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
     * List all contacts.
     *
     * @return \Illuminate\Http\Response
     */
    public function listContacts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100', // Adjust max per_page limit as needed
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
            ->when($searchTerm !== null && $searchTerm !== '', function ($q) use ($searchTerm) {
                $q->where(function ($sub) use ($searchTerm) {
                    $sub->where('contacts.first_name', 'like', "%{$searchTerm}%")
                        ->orWhere('contacts.last_name', 'like', "%{$searchTerm}%")
                        ->orWhere('contacts.phone', 'like', "%{$searchTerm}%")
                        ->orWhere('contacts.email', 'like', "%{$searchTerm}%")
                        ->orWhereRaw(
                            "CONCAT(contacts.first_name, ' ', contacts.last_name) LIKE ?",
                            ["%{$searchTerm}%"]
                        );
                });
            })
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
        if (!$this->isWhatsAppConnected($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('Please setup your whatsapp account!'),
            ], 403);
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
        if (!$this->isWhatsAppConnected($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('Please setup your whatsapp account!'),
            ], 403);
        }

        $contact = $this->resolveContactByPhone($request, $organizationId);

        $this->initializeWhatsappService($organizationId);
        $responseObject = $this->whatsappService->sendTemplateMessage($contact->uuid, $request->template, 0, null, null, $sendByQueue);

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
        if (!$this->isWhatsAppConnected($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('Please setup your whatsapp account!'),
            ], 403);
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
        if (!$this->isWhatsAppConnected($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('Please setup your whatsapp account!'),
            ], 403);
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
        $rules = [
            'phone' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (!PhoneService::isValid($value)) {
                    $fail('The phone number is not valid.');
                }
            }],
			'file' => 'required|file|mimes:jpg,jpeg,png,gif,bmp,webp,svg,ico,heic,heif,mp4,avi,mov,wmv,flv,mkv,webm,3gp,mpeg,mpg,mp3,wav,ogg,aac,m4a,flac,wma,amr,opus,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,rtf,odt,ods,odp|max:4096',
            'caption' => 'nullable',
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
        if (!$this->isWhatsAppConnected($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'success' => false,
                'message' => __('Please setup your whatsapp account!'),
            ], 403);
        }

        $contact = $this->resolveContactByPhone($request, $organizationId);

        $file = $request->file('file');
		logger('sendFileMessage');
		logger(json_encode([
            'uuid' => $contact->uuid,
            'file' => $file,
            'type'=> self::getFileTypeFromExtension($file->getClientOriginalExtension()) ,
            'caption' => $request->caption,
            'messageUUID' => $request->get('msg_uuid'),
        ]));
        $request->merge([
            'uuid' => $contact->uuid,
            'file' => $file,
            'type'=> self::getFileTypeFromExtension($file->getClientOriginalExtension()) ,
            'caption' => $request->caption,
            'messageUUID' => $request->get('msg_uuid'),
        ]);
        
        // +"message": "(#100) Param type must be one of {AUDIO, CONTACTS, DOCUMENT, GIF, IMAGE, INTERACTIVE, LINK_PREVIEW, LOCATION, PIN, REACTION, STICKER, TEMPLATE, TEXT, VIDEO} - got "jpeg"."

    
    
        $chatService = new ChatService($organizationId);
        $chatService->sendMessage($request);
        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Message sent successfully'),
            'data' => [
                'queued' => true,
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
        $settings = Organization::where('id', $organizationId)->first();
        $metadata = $settings->metadata ? json_decode($settings->metadata, true) : [];

        return isset($metadata['whatsapp']);
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
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $entityTypes = $request->input('message_types', []);
        $createdAt = $request->input('created_at', null);
        $searchTerm = $request->filled('search') ? (string) $request->input('search') : null;
        $orgId = (int) $organizationId;

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

       

        $contacts = $contactsQuery->orderBy('latest_chat_created_at', 'desc')->get();

        if ($contacts->isEmpty()) {
            return response()->json([
                'statusCode' => 200,
                'success' => true,
                'message' => __('Chat messages fetched successfully'),
                'data' => [],
            ], 200);
        }

        $contactIds = $contacts->pluck('id')->all();
        $contactsById = $contacts->keyBy('id');

        // Step 2: Load ALL chat_logs in one query (instead of N separate queries)
        $allChatLogs = ChatLog::whereIn('contact_id', $contactIds)
            ->whereNull('deleted_at')
            ->when($createdAt, fn ($q) => $q->where('created_at', '>=', $createdAt))
            ->when(!empty($entityTypes), fn ($q) => $q->whereIn('entity_type', $entityTypes))
            ->orderBy('created_at', 'asc')
            ->get();

        // Step 3: Batch-load all related entities (3 queries total instead of 3×N)
        $chatIds    = $allChatLogs->where('entity_type', 'chat')->pluck('entity_id')->unique()->filter()->values()->all();
        $ticketIds  = $allChatLogs->where('entity_type', 'ticket')->pluck('entity_id')->unique()->filter()->values()->all();
        $noteIds    = $allChatLogs->where('entity_type', 'notes')->pluck('entity_id')->unique()->filter()->values()->all();

        $chatsMap = !empty($chatIds)
            ? Chat::with('media', 'user', 'logs')->whereIn('id', $chatIds)->get()->keyBy('id')
            : collect();
        $ticketLogsMap = !empty($ticketIds)
            ? ChatTicketLog::whereIn('id', $ticketIds)->get()->keyBy('id')
            : collect();
        $notesMap = !empty($noteIds)
            ? ChatNote::whereIn('id', $noteIds)->get()->keyBy('id')
            : collect();

        // Step 4: Group by contact and build response (zero additional DB queries)
        $chatLogsByContact = $allChatLogs->groupBy('contact_id');
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

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Chat messages fetched successfully'),
            'data' => $results,
        ], 200);
    }
    /**
     * Format a chat record for the mobile API using the already-loaded contact
     * instead of re-querying it from DB (replaces minimalChatValue N+1).
     */
    private function formatChatValue($chat, $contact, ?string $formattedPhone = null, ?string $contactFullName = null): array
    {
        $arr = $chat instanceof \Illuminate\Database\Eloquent\Model ? $chat->toArray() : (array) $chat;

        $user = null;
        if (!empty($arr['user']) && is_array($arr['user'])) {
            $user = array_intersect_key($arr['user'], array_flip(['first_name', 'last_name']));
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
                $logs[] = ['metadata' => json_encode($minimal)];
            }
        }

        $metadata = $arr['metadata'] ?? null;
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $type = $metadata['type'] ?? null;
        if (is_array($metadata) && isset($metadata['type']) && $type && empty($metadata[$type])) {
            $metadata[$type] = null;
        }
        if (is_array($metadata)) {
            $metadata = json_encode($metadata);
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
            'status' => $arr['status'] ?? null,
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
