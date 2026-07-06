<?php

namespace App\Http\Controllers\User;

use App\Helpers\CustomHelper;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreWhatsappProfile;
use App\Http\Requests\StoreWhatsappSettings;
use App\Models\Addon;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\Template;
use App\Services\ContactFieldService;
use App\Services\OrganizationContextService;
use App\Services\UserAccountDeletionService;
use App\Services\WhatsappService;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Validator;

class SettingController extends BaseController
{
	private ContactFieldService $contactFieldService;
    public function __construct(ContactFieldService $contactFieldService)
    {
        $this->contactFieldService = $contactFieldService;
    }

    public function index(Request $request, $display = null){
        if ($request->isMethod('get')) {
            $organizationId = $this->webCurrentOrganizationId();
            $data['title'] = __('Settings');
            $data['settings'] = Organization::where('id', $organizationId)->first();
            $data['timezones'] = config('formats.timezones');
			$messageTemplates = Template::where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->where('status', 'APPROVED')
            ->select(['uuid as value', 'name as label'])
            ->get()->toArray();
			$data['templates'] = $messageTemplates;
		
            $data['countries'] = config('formats.countries');
            $data['sounds'] = config('sounds');
            $data['modules'] = Addon::get();
            $contactModel = new Contact;
            $data['contactGroups'] = $contactModel->getAllContactGroups($organizationId);
            $data = array_merge($data, $this->leaveOrganizationInertiaProps($organizationId));
            return Inertia::render('User/Settings/General', $data);
        }
    }

    public function mobileView(Request $request){
        $data['title'] = __('Settings');
        $data['settings'] = Organization::where('id', session()->get('current_organization'))->first();
        return Inertia::render('User/Settings/Main', $data);
    }

    public function viewGeneralSettings(Request $request){
        $contactModel = new Contact;
        $organizationId = $this->webCurrentOrganizationId();
        $data['title'] = __('Settings');
        $data['settings'] = Organization::where('id', $organizationId)->first();
        $data['modules'] = Addon::get();
        $data['contactGroups'] = $contactModel->getAllContactGroups($organizationId);
        $data = array_merge($data, $this->leaveOrganizationInertiaProps($organizationId));

        return Inertia::render('User/Settings/General', $data);
    }

    public function leaveCurrentOrganization(Request $request, OrganizationContextService $organizationContext)
    {
        if ($demo = $this->abortIfDemo()) {
            return $demo;
        }

        $user = auth()->user();
        $organizationId = $this->webCurrentOrganizationId();

        $detach = $organizationContext->detachMembership($user, $organizationId);
        if (!$detach['ok']) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $detach['message'],
            ]);
        }

        $user->refresh();
        $organizationContext->ensureValid($user, OrganizationContextService::PLATFORM_WEB);

        if ($user->fresh()->canNotAccessDashboard()) {
            Auth::guard('user')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', [
                'type' => 'success',
                'message' => __('You have left the organization and been signed out.'),
            ]);
        }

        return redirect()->route('user.organization.index')->with('status', [
            'type' => 'success',
            'message' => __('You have left this organization.'),
        ]);
    }

    public function deleteAccount(Request $request, UserAccountDeletionService $userAccountDeletionService)
    {
        if ($demo = $this->abortIfDemo()) {
            return $demo;
        }

        $user = auth()->user();
        if (!$user) {
            abort(403);
        }

        $result = $userAccountDeletionService->softDeleteDashboardUser($user);
        if (!$result['ok']) {
            return back()->with('status', [
                'type' => 'error',
                'message' => $result['message'],
            ]);
        }

        Auth::guard('user')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', [
            'type' => 'success',
            'message' => __('Your account has been deleted.'),
        ]);
    }

    public function viewWhatsappSettings(Request $request){
        $settings = Setting::whereIn('key', ['is_embedded_signup_active', 'whatsapp_client_id', 'whatsapp_config_id'])
            ->pluck('value', 'key');

        $data = [
            'embeddedSignupActive' => CustomHelper::isModuleEnabled('Embedded Signup'),
            'graphAPIVersion' => config('graph.api_version'),
            'appId' => $settings->get('whatsapp_client_id', null),
            'configId' => $settings->get('whatsapp_config_id', null),
            'settings' => Organization::where('id', session()->get('current_organization'))->first(),
            'modules' => Addon::get(),
            'title' => __('Settings'),
        ];

        return Inertia::render('User/Settings/Whatsapp', $data);
    }

    public function storeWhatsappSettings(StoreWhatsappSettings $request) {
	
        $embeddedSignupActive = Setting::where('key', 'is_embedded_signup_active')->value('value');
        $setWebhookUrl = $embeddedSignupActive == 1 ? true : false;

        return $this->saveWhatsappSettings(
            $request->access_token,
            $request->app_id,
            $request->phone_number_id,
            $request->waba_id,
            $setWebhookUrl
        );
    }

    public function updateToken(Request $request) {
        if ($response = $this->abortIfDemo()) {
            return $response;
        }
        
        $organizationId = session()->get('current_organization');
        $config = Organization::findOrFail($organizationId)->metadata;
        $config = $config ? json_decode($config, true) : [];

        return $this->saveWhatsappSettings(
            $request->access_token,
            $config['whatsapp']['app_id'] ?? null,
            $config['whatsapp']['phone_number_id'] ?? null,
            $config['whatsapp']['waba_id'] ?? null
        );
    }
    
    public function refreshWhatsappData() {
        $organizationId = session()->get('current_organization');
        $config = Organization::findOrFail($organizationId)->metadata;
        $config = $config ? json_decode($config, true) : [];

        if($config['whatsapp']['is_embedded_signup'] && $config['whatsapp']['is_embedded_signup'] == 1){
            if (class_exists(\Modules\EmbeddedSignup\Services\MetaService::class)) {
                $embeddedSetup = new \Modules\EmbeddedSignup\Services\MetaService();
                $embeddedSetup->overrideWabaCallbackUrl($organizationId);
            }
        }
    
        return $this->saveWhatsappSettings(
            $config['whatsapp']['access_token'] ?? null,
            $config['whatsapp']['app_id'] ?? null,
            $config['whatsapp']['phone_number_id'] ?? null,
            $config['whatsapp']['waba_id'] ?? null
        );
    }

    public function contacts(Request $request){
        if ($request->isMethod('get')) {
            $contactFieldService = new ContactFieldService(session()->get('current_organization'));
            $settings = Organization::where('id', session()->get('current_organization'))->first();

            return Inertia::render('User/Settings/Contact', [
                'title' => __('Settings'),
                'filters' => $request->all(),
                'rows' => $contactFieldService->get($request),
                'settings' => $settings,
                'modules' => Addon::get(),
            ]);
        } else if($request->isMethod('post')) {
            $currentOrganizationId = session()->get('current_organization');
            $organizationConfig = Organization::where('id', $currentOrganizationId)->first();
    
            $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

            $metadataArray['contacts']['location'] = $request->location;

            $updatedMetadataJson = json_encode($metadataArray);

            $organizationConfig->metadata = $updatedMetadataJson;
            $organizationConfig->save();

            return back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Settings updated successfully')
                ]
            );
        }
    }

    public function tickets(Request $request){
        if ($request->isMethod('get')) {
            $contactFieldService = new ContactFieldService(session()->get('current_organization'));
            $settings = Organization::where('id', session()->get('current_organization'))->first();

            return Inertia::render('User/Settings/Ticket', [
                'title' => __('Settings'),
                'filters' => $request->all(),
                'rows' => $contactFieldService->get($request),
                'settings' => $settings,
                'modules' => Addon::get(),
            ]);
        } else if($request->isMethod('post')) {
            $currentOrganizationId = session()->get('current_organization');
            $organizationConfig = Organization::where('id', $currentOrganizationId)->first();
    
            $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

            $metadataArray['tickets']['active'] = $request->active;
            $metadataArray['tickets']['auto_assignment'] = $request->auto_assignment;
            $metadataArray['tickets']['reassign_reopened_chats'] = $request->reassign_reopened_chats;
            $metadataArray['tickets']['allow_agents_to_view_all_chats'] = $request->allow_agents_to_view_all_chats;
            $metadataArray['tickets']['encrypt_contacts_for_agents'] = $request->encrypt_contacts_for_agents;

            $updatedMetadataJson = json_encode($metadataArray);

            $organizationConfig->metadata = $updatedMetadataJson;
            $organizationConfig->save();

            /*return back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Settings updated successfully')
                ]
            );*/
        }
    }

    public function automation(Request $request){
        if ($request->isMethod('get')) {
            $settings = Organization::where('id', session()->get('current_organization'))->first();

            return Inertia::render('User/Settings/Automation', [
                'title' => __('Settings'),
                'settings' => $settings,
                'modules' => Addon::get(),
            ]);
        } else if($request->isMethod('post')) {
            $currentOrganizationId = session()->get('current_organization');
            $organizationConfig = Organization::where('id', $currentOrganizationId)->first();
    
            $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
            $metadataArray['automation']['response_sequence'] = $request->response_sequence;

            $updatedMetadataJson = json_encode($metadataArray);
            $organizationConfig->metadata = $updatedMetadataJson;
            $organizationConfig->save();

            /*return back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Settings updated successfully')
                ]
            );*/
        }
    }

    public function devices(Request $request)
    {
        return Inertia::render('User/Settings/Devices', [
            'title' => __('Settings'),
            'modules' => Addon::get(),
        ]);
    }

    public function whatsappBusinessProfileUpdate(StoreWhatsappProfile $request){
        if ($response = $this->abortIfDemo()) {
            return $response;
        }

        $organizationId = session()->get('current_organization');
        $config = Organization::where('id', $organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        if(isset($config['whatsapp'])){
            $accessToken = $config['whatsapp']['access_token'] ?? null;
            $apiVersion = config('graph.api_version');
            $appId = $config['whatsapp']['app_id'] ?? null;
            $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
            $wabaId = $config['whatsapp']['waba_id'] ?? null;

            $whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);
            
            $response = $whatsappService->updateBusinessProfile($request);

            if($response->success === true){
                return back()->with(
                    'status', [
                        'type' => 'success', 
                        'message' => __('Your whatsapp business profile has been changed successfully!')
                    ]
                );
            } else {
                return back()->with(
                    'status', [
                        'type' => 'error', 
                        'message' => __('Something went wrong! Your business profile could not be updated!')
                    ]
                );
            }
        }

        return back()->with(
            'status', [
                'type' => 'error', 
                'message' => __('Setup your whatsapp integration first!')
            ]
        );
    }

    public function deleteWhatsappIntegration(Request $request){
        if ($response = $this->abortIfDemo()) {
            return $response;
        }

        $embeddedSignupActive = Setting::where('key', 'is_embedded_signup_active')->value('value');
        $organizationId = session()->get('current_organization');
        $organizationConfig = Organization::where('id', $organizationId)->first();
        $config = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

        if(isset($config['whatsapp'])){
            if($embeddedSignupActive == 1){
                //Unsubscribe webhook
                $organizationId = session()->get('current_organization');
                $apiVersion = config('graph.api_version');

                $accessToken = $config['whatsapp']['access_token'] ?? null;
                $appId = $config['whatsapp']['app_id'] ?? null;
                $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
                $wabaId = $config['whatsapp']['waba_id'] ?? null;
            
                $whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);
                $unsubscribe = $whatsappService->unSubscribeToWaba();
            }
            
            //Delete whatsapp settings
            if (isset($config['whatsapp'])) {
                unset($config['whatsapp']);
            }

            $updatedMetadataJson = json_encode($config);
            $organizationConfig->metadata = $updatedMetadataJson;
            $organizationConfig->save();

            //Delete templates
            $templates = Template::where('organization_id', $organizationId)->get();
            foreach ($templates as $template) {
                $template->deleted_at = now();
                $template->save();
            }

            return back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Your integration has been removed successfully!')
                ]
            );
        }

        return back()->with(
            'status', [
                'type' => 'error', 
                'message' => __('Setup your whatsapp integration first!')
            ]
        );
    }

    private function saveWhatsappSettings($accessToken, $appId, $phoneNumberId, $wabaId, $subscribeToWebhook = false) {
        $organizationId = session()->get('current_organization');
        $apiVersion = config('graph.api_version');
    
        $whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);

        $phoneNumberResponse = $whatsappService->getPhoneNumberId($accessToken, $wabaId);
        
        if(!$phoneNumberResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $phoneNumberResponse->data->error->message
                ]
            );
        }

        //Get Phone Number Status
        $phoneNumberStatusResponse = $whatsappService->getPhoneNumberStatus($accessToken, $phoneNumberResponse->data->id); 
        
        if(!$phoneNumberStatusResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $phoneNumberStatusResponse->data->error->message
                ]
            );
        }

        //Get Account Review Status
        $accountReviewStatusResponse = $whatsappService->getAccountReviewStatus($accessToken, $wabaId);
        
        if(!$accountReviewStatusResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $accountReviewStatusResponse->data->error->message
                ]
            );
        }

        //Get business profile
        $businessProfileResponse = $whatsappService->getBusinessProfile($accessToken, $phoneNumberResponse->data->id);  
        
        if(!$businessProfileResponse->success){
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $businessProfileResponse->data->error->message
                ]
            );
        }

        $organizationConfig = Organization::where('id', $organizationId)->first();
        
        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
        $metadataArray['whatsapp']['is_embedded_signup'] = $metadataArray['whatsapp']['is_embedded_signup'] ?? 0;
        $metadataArray['whatsapp']['access_token'] = $accessToken;
        $metadataArray['whatsapp']['app_id'] = $appId;
        $metadataArray['whatsapp']['waba_id'] = $wabaId;
        $metadataArray['whatsapp']['phone_number_id'] = $phoneNumberResponse->data->id;
        $metadataArray['whatsapp']['display_phone_number'] = $phoneNumberResponse->data->display_phone_number;
        $metadataArray['whatsapp']['verified_name'] = $phoneNumberResponse->data->verified_name;
        $metadataArray['whatsapp']['quality_rating'] = isset($phoneNumberResponse->data->quality_rating) ? $phoneNumberResponse->data->quality_rating : NULL;
        $metadataArray['whatsapp']['name_status'] = $phoneNumberResponse->data->name_status;
        $metadataArray['whatsapp']['messaging_limit_tier'] = $phoneNumberResponse->data->messaging_limit_tier ?? NULL;
        $metadataArray['whatsapp']['max_daily_conversation_per_phone'] = NULL;
        $metadataArray['whatsapp']['max_phone_numbers_per_business'] = NULL;
        $metadataArray['whatsapp']['number_status'] = $phoneNumberStatusResponse->data->status;
        $metadataArray['whatsapp']['code_verification_status'] = $phoneNumberStatusResponse->data->code_verification_status;
        $metadataArray['whatsapp']['business_verification'] = '';
        $metadataArray['whatsapp']['account_review_status'] = $accountReviewStatusResponse->data->account_review_status;
        $metadataArray['whatsapp']['business_profile']['about'] = $businessProfileResponse->data->about ?? NULL;
        $metadataArray['whatsapp']['business_profile']['address'] = $businessProfileResponse->data->address ?? NULL;
        $metadataArray['whatsapp']['business_profile']['description'] = $businessProfileResponse->data->description ?? NULL;
        $metadataArray['whatsapp']['business_profile']['industry'] = $businessProfileResponse->data->vertical ?? NULL;
        $metadataArray['whatsapp']['business_profile']['email'] = $businessProfileResponse->data->email ?? NULL;

        $updatedMetadataJson = json_encode($metadataArray);
        $organizationConfig->metadata = $updatedMetadataJson;

        if($organizationConfig->save()){
            $whatsappService->syncTemplates($accessToken, $wabaId);

            return back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Whatsapp settings updated successfully')
                ]
            );
        } else {
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('Something went wrong. Refresh the page and try again')
                ]
            );
        }
    }

    public function workingHours(Request $request)
    {
        $organizationId = (int) session()->get('current_organization');
        if (!CustomHelper::isModuleEnabled('Working Hours', $organizationId)) {
            abort(403);
        }

        if ($request->isMethod('get')) {
            $settings = Organization::where('id', $organizationId)->firstOrFail();
            $metadata = $settings->metadata ? json_decode($settings->metadata, true) : [];
            $slots = $metadata['working_hours'] ?? [];
            if (!is_array($slots)) {
                $slots = [];
            }

            $outsideMessage = $metadata['working_hours_outside_message'] ?? '';
            if (!is_string($outsideMessage)) {
                $outsideMessage = '';
            }

            return Inertia::render('User/Settings/WorkingHours', [
                'title' => __('Settings'),
                'modules' => Addon::get(),
                'working_hours' => array_values($slots),
                'working_hours_outside_message' => $outsideMessage,
                'placeholders' => $this->replyMessagePlaceholdersForOrganization($organizationId),
            ]);
        }

        if ($response = $this->abortIfDemo()) {
            return $response;
        }

        $validated = $request->validate([
            'slots' => 'nullable|array|max:64',
            'slots.*.day' => 'required|integer|between:0,6',
            'slots.*.open' => 'required|regex:/^\d{2}:\d{2}$/',
            'slots.*.close' => 'required|regex:/^\d{2}:\d{2}$/',
            'working_hours_outside_message' => 'nullable|string|max:4096',
        ]);

        foreach ($validated['slots'] ?? [] as $slot) {
            if (strcmp($slot['open'], $slot['close']) >= 0) {
                return back()->with('status', [
                    'type' => 'error',
                    'message' => __('Each working hours row must have an end time after the start time.'),
                ]);
            }
        }

        $normalizedSlots = array_map(function (array $slot) {
            return [
                'day' => (int) $slot['day'],
                'open' => substr($slot['open'], 0, 5),
                'close' => substr($slot['close'], 0, 5),
            ];
        }, $validated['slots'] ?? []);

        $organizationConfig = Organization::where('id', $organizationId)->firstOrFail();
        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
        $metadataArray['working_hours'] = $normalizedSlots;
        $metadataArray['working_hours_outside_message'] = $validated['working_hours_outside_message'] ?? '';

        $organizationConfig->metadata = json_encode($metadataArray);
        $organizationConfig->save();

        return back()->with('status', [
            'type' => 'success',
            'message' => __('Settings updated successfully'),
        ]);
    }

    protected function abortIfDemo(){
        $organizationId = session()->get('current_organization');

        if (app()->environment('demo') && $organizationId == 1) {
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('You cannot perform this action using the demo account. To test this feature, please create your own account.')
                ]
            );
        }

        return null;
    }

    /**
     * Placeholder options for working-hours outbound text: built-ins + org contact fields.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function replyMessagePlaceholdersForOrganization(int $organizationId): array
    {
        $placeholders = config('formats.placeholders') ?? [];
        if (! is_array($placeholders)) {
            $placeholders = [];
        }

        $additionalFields = DB::table('contact_fields')
            ->where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->pluck('name');

        $additionalPlaceholders = $additionalFields->map(static function ($name) {
            return [
                'value' => '{' . strtolower(str_replace(' ', '_', $name)) . '}',
                'label' => $name,
            ];
        })->toArray();

        $additionalUrlPlaceholders = $additionalFields->map(static function ($name) {
            return [
                'value' => '{url:' . strtolower(str_replace(' ', '_', $name)) . '}',
                'label' => $name . ' (URL encoded)',
            ];
        })->toArray();

        return array_merge($placeholders, $additionalPlaceholders, $additionalUrlPlaceholders);
    }

    /**
     * Prefer session (legacy) then DB column so settings stay consistent if the
     * session key is missing while current_web_organization_id is valid.
     */
    private function webCurrentOrganizationId(): int
    {
        $fromSession = (int) session()->get('current_organization', 0);
        if ($fromSession > 0) {
            return $fromSession;
        }

        $user = auth()->user();
        if ($user && ($user->role ?? null) === 'user') {
            return (int) ($user->current_web_organization_id ?? 0);
        }

        return 0;
    }

    /**
     * @return array{leaveOrganizationSectionVisible: bool, canDetachFromCurrentOrganization: bool}
     */
    private function leaveOrganizationInertiaProps(int $organizationId): array
    {
        if ($organizationId <= 0 || ! auth()->check()) {
            return [
                'leaveOrganizationSectionVisible' => false,
                'canDetachFromCurrentOrganization' => false,
            ];
        }

        $team = Team::query()
            ->where('user_id', auth()->id())
            ->where('organization_id', $organizationId)
            ->first();

        $user = auth()->user();
        $ownsAnyOrganization = $user
            ? Team::query()
                ->where('user_id', $user->id)
                ->where('role', 'owner')
                ->exists()
            : false;

        return [
            'leaveOrganizationSectionVisible' => $team !== null,
            'canDetachFromCurrentOrganization' => $team !== null && $team->role !== 'owner',
            'canDeleteAccount' => $user && ($user->role ?? null) === 'user' && !$ownsAnyOrganization,
        ];
    }
}
