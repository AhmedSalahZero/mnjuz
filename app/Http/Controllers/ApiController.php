<?php

namespace App\Http\Controllers;

use App\Helpers\WebhookHelper;
use App\Http\Requests\Api\StoreContactRequest;
use App\Http\Requests\StoreContact;
use App\Http\Resources\AutoReplyResource;
use App\Http\Resources\ContactGroupResource;
use App\Http\Resources\ContactResource;
use App\Http\Resources\TemplateResource;
use App\Models\AutoReply;
use App\Models\Chat;
use App\Models\ChatMedia;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Template;
use App\Rules\CannedReplyLimit;
use App\Rules\ContactLimit;
use App\Rules\UniquePhone;
use App\Services\ChatService;
use App\Services\ContactService;
use App\Services\MediaService;
use App\Services\PhoneService;
use App\Services\SubscriptionService;
use App\Services\WhatsappService;
use App\Traits\TemplateTrait;
use Illuminate\Http\Request;
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
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 10);
		$organizationId = $request->organization;
		if( $request->is('api/v1/*')){
			$organizationId = $request->user()->current_organization_id;
		}
        $contacts = Contact::where('organization_id', $organizationId)
            ->where('deleted_at', null)
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
		if( $request->is('api/v1/*')){
			$organizationId = $request->user()->current_organization_id;
		}
        if (!SubscriptionService::isSubscriptionActive($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'message' => __('Please renew or subscribe to a plan to continue!',[],getApiLang()),
            ], 403);
        }

        if (!SubscriptionService::isSubscriptionFeatureLimitReached($organizationId, 'contacts_limit')) {
            return response()->json([
                'statusCode' => 403,
                'message' => __('You have reached your limit of contacts. Please upgrade your account to add more!',[],getApiLang()),
            ], 403);
        }

        try {
            $contactService = new ContactService($organizationId);
            $contact = $contactService->store($request, null); // null for create
			if( $request->is('api/v1/*')){
				return response()->json([
					'statusCode' => 200,
					'success' => true,
					'data' => [
						'uuid' => $contact->uuid,
					],
					'message' => __('Request processed successfully',[],getApiLang())
				], 200);
			}
            return response()->json([
                'statusCode' => 200,
				'success' => true,
                'id' => $contact->uuid,
                'message' => __('Request processed successfully',[],getApiLang())
            ], 200);
        } catch (\Exception $e) {
			if( $request->is('api/v1/*')){
				return response()->json([
					'statusCode' => 500,
					'success' => false,
					'data' => [],
					'message' => __('Request unable to be processed',[],getApiLang())
				], 500);
			}
            return response()->json([
                'statusCode' => 500,
				'success' => false,
                'message' => __('Request unable to be processed',[],getApiLang())
            ], 500);
        }
    }

    /**
     * Update an existing contact.
     */
    public function updateContact(StoreContactRequest $request, string $uuid)
    {
		$organizationId = $request->organization;
		if( $request->is('api/v1/*')){
			$organizationId = $request->user()->current_organization_id;
		}
        if (!SubscriptionService::isSubscriptionActive($organizationId)) {
            return response()->json([
                'statusCode' => 403,
                'message' => __('Please renew or subscribe to a plan to continue!',[],getApiLang()),
            ], 403);
        }

        try {
            $contactService = new ContactService($organizationId);
            $contact = $contactService->store($request, $uuid); // uuid for update
			if( $request->is('api/v1/*')){
				return response()->json([
					'statusCode' => 200,
					'success' => true,
					'data' => [
						'uuid' => $contact->uuid,
					],
					'message' => __('Request processed successfully',[],getApiLang())
				], 200);
			}
            return response()->json([
                'statusCode' => 200,
                'id' => $contact->uuid,
                'message' => __('Request processed successfully',[],getApiLang())
            ], 200);
        } catch (\Exception $e) {
		
			if( $request->is('api/v1/*')){
				return response()->json([
					'statusCode' => 500,
					'success' => false,
					'data' => [],
					'message' => __('Request unable to be processed',[],getApiLang())
				], 500);
			}
            return response()->json([
                'statusCode' => 500,
                'message' => __('Request unable to be processed',[],getApiLang())
            ], 500);
        }
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
		if( $request->is('api/v1/*')){
			$organizationId = $request->user()->current_organization_id;
		}
        try {
            $contactService = new ContactService($organizationId);
            $contactService->delete([$uuid]);
			if( $request->is('api/v1/*')){
				return response()->json([
					'statusCode' => 200,
					'success' => true,
					'data' => [],
					'message' => __('Request processed successfully',[],getApiLang())
				], 200);
			}
            return response()->json([
                'statusCode' => 200,
                'id' => $uuid,
                'message' => __('Request processed successfully',[],getApiLang())
            ], 200);
        } catch (\Exception $e) {
			if( $request->is('api/v1/*')){
				return response()->json([
					'statusCode' => 500,
					'success' => false,
					'data' => [],
					'message' => __('Request unable to be processed',[],getApiLang())
				], 500);
			}
            return response()->json([
                'statusCode' => 500,
                'message' => __('Request unable to be processed',[],getApiLang())
            ], 500);
        }
    }

   
    public function listContactGroups(Request $request)
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
		if( $request->is('api/v1/*')){
			$organizationId = $request->user()->current_organization_id;
		}
        $contactGroups = ContactGroup::where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->paginate($perPage, ['*'], 'page', $page);

        return ContactGroupResource::collection($contactGroups);
    }

   
    public function storeContactGroup(Request $request, $uuid = null)
    {
        $organizationId = $request->organization;
		if( $request->is('api/v1/*')){
			$organizationId = $request->user()->current_organization_id;
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
            $contactGroup->name = $request->name;
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
		if( $request->is('api/v1/*')){
			$organizationId = $request->user()->current_organization_id;
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

        if ($request->isMethod('post')) {
            if (!SubscriptionService::isSubscriptionFeatureLimitReached($request->organizationId, 'canned_replies_limit')) {
                return response()->json([
                    'statusCode' => 403,
                    'message' => __('You\'ve reached your limit. Upgrade your account'),
                ], 403);
            }
        }

        try {
            $model = $uuid == null ? new AutoReply : AutoReply::where('uuid', $uuid)->first();
            $model['name'] = $request->name;
            $model['trigger'] = $request->trigger;
            $model['match_criteria'] = $request->match_criteria;

            $metadata['type'] = $request->response_type;
            if ($request->response_type === 'image' || $request->response_type === 'audio') {
                if ($request->hasFile('response')) {
                    $uploadedMedia = MediaService::upload($request->file('response'));

                    $metadata['data']['file']['name'] = $uploadedMedia['name'];
                    $metadata['data']['file']['location'] = $uploadedMedia['path'];
                } else {
                    $media = json_decode($model->metadata)->data;
                    $metadata['data']['file']['name'] = $media->file->name;
                    $metadata['data']['file']['location'] = $media->file->location;
                }
            } elseif ($request->response_type === 'text') {
                $metadata['data']['text'] = $request->response;
            } else {
                $metadata['data']['template'] = $request->response;
            }

            $model['metadata'] = json_encode($metadata);
            $model['updated_at'] = now();

            if ($uuid === null) {
                $model['organization_id'] = $request->organization;
                $model['created_by'] = 0;
                $model['created_at'] = now();
            }

            $model->save();

            // Prepare a clean contact object for webhook
            $cleanModel = $model->makeHidden(['id', 'organization_id', 'created_by']);

            // Trigger webhook
            WebhookHelper::triggerWebhookEvent($uuid === null ? 'autoreply.created' : 'autoreply.updated', $cleanModel, $request->organization);

            return response()->json([
                'statusCode' => 200,
                'id' => $model->uuid,
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
		if( $request->is('api/v1/*')){
			$organizationId = $request->user()->current_organization_id;
			$request->merge(['tempMessageId' => -1]); // to use queue to send message in background
		}
        $rules = [
            'phone' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (!PhoneService::isValid($value)) {
                    $fail('The phone number is not valid.');
                }
            }],
            'message' => 'required',
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

        // Check if the contact exists, if not, create a new one
        $phone = $request->phone;

        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }

        $phone = new PhoneNumber($phone);
        $phone = $phone->formatE164();

        $contact = Contact::where('organization_id', $organizationId)->where('phone', $phone)->first();

        if (!$contact) {
            $contact = new Contact();
            $contact->organization_id = $organizationId;
            $contact->first_name = $request->first_name;
            $contact->last_name = $request->last_name;
            $contact->email = $request->email;
            $contact->phone = $phone;
            $contact->created_by = 0;
            $contact->save();
        }

        // Extract the UUID of the contact
        $this->initializeWhatsappService($organizationId);
        $type = !isset($request->buttons) ? 'text' : 'interactive buttons';

        $header = [];
        if ($request->header) {
            $header['type'] = 'text';
            $header['text'] = clean($request->header);
        }
		
        $message = $this->whatsappService->sendMessage($contact->uuid, $request->message, 0, $type, $request->buttons, $header, $request->footer);
        
        return response()->json([
            'statusCode' => 200,
			'success' => true,
            'data' => $message
        ], 200);
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

        // Check if the contact exists, if not, create a new one
        $phone = $request->phone;

        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }

        $phone = new PhoneNumber($phone);
        $phone = $phone->formatE164();

        $contact = Contact::where('phone', $phone)->where('organization_id', $organizationId)
            ->whereNull('deleted_at')->first();

        if (!$contact) {
            $contact = new Contact();
            $contact->organization_id = $organizationId;
            $contact->first_name = $request->first_name;
            $contact->last_name = $request->last_name;
            $contact->email = $request->email;
            $contact->phone = $phone;
            $contact->created_by = 0;
            $contact->save();
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
		
		
		$organizationId = $request->user()->current_organization_id;
			$sendByQueue = true;
		$template = Template::where('uuid', $request->template_uuid)->where('organization_id', $organizationId)->first();
		if(!$template){
			return response()->json([
				'statusCode' => 404,
				'success' => false,
				'message' => __('Template not found!'),
			], 404);
		}
		$templateContent = json_decode($template->metadata,true);
		$templateContent  = [
			'name' => $template->name,
			'language' => [
				'code' => $template->language,
			],
		//	'components' => $templateContent['components'],
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

        // Check if the contact exists, if not, create a new one
        $phone = $request->phone;

        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }

        $phone = new PhoneNumber($phone);
        $phone = $phone->formatE164();

        $contact = Contact::where('phone', $phone)->where('organization_id', $organizationId)
            ->whereNull('deleted_at')->first();

        if (!$contact) {
            $contact = new Contact();
            $contact->organization_id = $organizationId;
            $contact->first_name = $request->first_name;
            $contact->last_name = $request->last_name;
            $contact->email = $request->email;
            $contact->phone = $phone;
            $contact->created_by = 0;
            $contact->save();
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
	
    public function sendMediaMessage(Request $request)
    {
        $organizationId = $request->organization;
		
		// if( $request->is('api/v1/*')){
		// 	$organizationId = $request->user()->current_organization_id;
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

        // Check if the contact exists, if not, create a new one
        $phone = $request->phone;

        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }

        $phone = new PhoneNumber($phone);
        $phone = $phone->formatE164();

        $contact = Contact::where('organization_id', $organizationId )->where('phone', $phone)->first();

        if (!$contact) {
            $contact = new Contact();
            $contact->organization_id = $organizationId;
            $contact->first_name = $request->first_name;
            $contact->last_name = $request->last_name;
            $contact->email = $request->email;
            $contact->phone = $phone;
            $contact->created_by = 0;
            $contact->save();
        }

        // Extract the UUID of the contact
        $this->initializeWhatsappService($organizationId);
        $type = !isset($request->buttons) ? 'text' : 'interactive';

        $message = $this->whatsappService->sendMedia($contact->uuid, $request->media_type, $request->file_name, $request->media_url, $request->media_url, 'amazon');
        
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
		$organizationId = $request->user()->current_organization_id;
		$request->merge(['tempMessageId' => -1]); // to use queue to send message in background
        $rules = [
            'phone' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (!PhoneService::isValid($value)) {
                    $fail('The phone number is not valid.');
                }
            }],
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,ppt,pptx|max:2048',
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

        // Check if the contact exists, if not, create a new one
        $phone = $request->phone;

        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }

        $phone = new PhoneNumber($phone);
        $phone = $phone->formatE164();

        $contact = Contact::where('organization_id', $organizationId )->where('phone', $phone)->first();

        if (!$contact) {
            $contact = new Contact();
            $contact->organization_id = $organizationId;
            $contact->first_name = $request->first_name;
            $contact->last_name = $request->last_name;
            $contact->email = $request->email;
            $contact->phone = $phone;
            $contact->created_by = 0;
            $contact->save();
        }
		$file = $request->file('file');

		$request->merge([
			'uuid' => $contact->uuid,
			'file' => $file,
			'type'=> self::getFileTypeFromExtension($file->getClientOriginalExtension()) ,
			'caption' => $request->caption,
		]);
		
		// +"message": "(#100) Param type must be one of {AUDIO, CONTACTS, DOCUMENT, GIF, IMAGE, INTERACTIVE, LINK_PREVIEW, LOCATION, PIN, REACTION, STICKER, TEMPLATE, TEXT, VIDEO} - got "jpeg"."

	
	
		$chatService = new ChatService($organizationId);
		 $chatService->sendMessage($request);
		
        return response()->json([
            'statusCode' => 200,
			'success' => true,
			'message' => __('Message sent successfully'),
         //   'data' => $message
        ], 200);
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
		if( $request->is('api/v1/*')){
			$organizationId = $request->user()->current_organization_id;
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
		if($request->is('api/v1/*')){
			$organizationId = $request->user()->current_organization_id;
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
	public function listChatContactsForContact(Request $request,$contactUuid)
    {
		$organizationId = $request->organization;
		if( $request->is('api/v1/*')){
			$organizationId = $request->user()->current_organization_id;
		}
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100', // Adjust max per_page limit as needed
        ]);
		$uuid = $contactUuid;
		$contact = Contact::where('uuid', $uuid)->where('organization_id', $organizationId)->first();
		if(!$contact){
			return response()->json([
				'statusCode' => 404,
				'success' => false,
				'message' => __('Contact not found'),
			], 404);
		}
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 10);
		
       return  (new ChatService($organizationId))->getChatMessages( $contact->id,$page,$perPage);
		
    }
	public function listChatMessagesFromChatIdToEnd(Request $request)
	{
		$organizationId = $request->organization;
		if( $request->is('api/v1/*')){
			$organizationId = $request->user()->current_organization_id;
		}
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
			'chat_log_id' => 'required|integer|min:1',
			'chat_log_type' => 'required|string|max:255',
            'per_page' => 'integer|min:1|max:100', // Adjust max per_page limit as needed
        ]);
		if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }
		$chatLogId = $request->input('chat_log_id', null);
		$chatLogType = $request->input('chat_log_type', null);
		$page = $request->input('page', 1);
		$perPage = $request->input('per_page', 10);
		$organization = Organization::where('id', $organizationId)->first();
		$contacts = $organization->contacts;
		$results = [];
		foreach($contacts as $contact){
			$result =(new ChatService($organizationId))->getChatMessages( $contact->id,$page,$perPage,$chatLogId,$chatLogType);
			if(isset($result['messages']) && count($result['messages']) > 0){
				$results[$contact->uuid] = $result;
			}
		}
		return response()->json([
			'statusCode' => 200,
			'success' => true,
			'message' => __('Chat messages fetched successfully'),
			'data' => $results
		], 200);
	}
	public function deleteChatForContact(Request $request,$uuid)
	{
		$organizationId = $request->user()->current_organization_id;
		$contact = Contact::where('uuid', $uuid)->where('organization_id', $organizationId)->first();
		if(!$contact){
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
	public function toggleTicketStatus(Request $request,$uuid)
	{
		$organizationId = $request->user()->current_organization_id;
		$contact = Contact::where('uuid', $uuid)->where('organization_id', $organizationId)->first();
		if(!$contact){
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

        $organizationId = $request->user()->current_organization_id;
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
}
