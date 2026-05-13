<?php

namespace App\Http\Controllers;

use App\Helpers\DateTimeHelper;
use App\Helpers\Email;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\ResetDevicesRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\PasswordResetRequest;
use App\Http\Requests\PasswordValidateResetRequest;
use App\Http\Requests\SignupRequest;
use App\Http\Requests\StoreUser;
use App\Http\Requests\StoreUserInvite;
use App\Http\Requests\TfaRequest;
use App\Http\Requests\UserHasOrganizationRequest;
use App\Models\Addon;
use App\Models\Organization;
use App\Models\PasswordResetToken;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use App\Models\UserDevice;
use App\Mail\ResetLinkedDevicesMail;
use App\Services\UserDeviceService;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use App\Services\SocialLoginService;
use App\Services\TeamService;
use App\Services\OrganizationContextService;
use App\Services\UserAccountDeletionService;
use App\Services\UserVerificationService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AuthController extends BaseController
{
    protected $userService;
    protected $role;
    protected UserDeviceService $userDeviceService;
    protected UserVerificationService $userVerificationService;

    public function __construct($role = 'user')
    {
        $this->userService = new UserService($role);
        $this->role = $role;
        $this->userDeviceService = new UserDeviceService();
        $this->userVerificationService = new UserVerificationService();
    }

    public function showLoginForm(){
        $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period', 'allow_facebook_login', 'allow_google_login'];
        $data['companyConfig'] = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();

        return Inertia::render('Auth/Login', $data);
    }
	public function showTfaForm(Request $request)
    {
        if (!$request->session()->has('tfa')) {
            return redirect('/login');
        }

        $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period', 'allow_facebook_login','allow_google_login'];

        $data['companyConfig'] = Setting::whereIn('key', $keys)
            ->pluck('value', 'key')
            ->toArray();

        return Inertia::render('Auth/Tfa', $data);
    }
	
    public function login(LoginRequest $request){
		
		$user = User::where('email', $request->email)->where('deleted_at', null)->first();
        $addon = Addon::where('name', 'Google Authenticator')->first();
        $addonActive = ($addon && ($addon->is_active == 1 || $addon->is_active === '1' || $addon->is_active === true)) ? 1 : 0;
        $remember = $request->remember;
		$userLanguage = $user->language ?? 'en';
		// check if there is an active originization
		// $numberOfActiveOrganization = $user->getActiveOrganizations()->count();
		$canNotAccessDashboard = $user->canNotAccessDashboard();
		if($canNotAccessDashboard){
            // Check if this is an API request (mobile)
            if ($request->expectsJson() || $request->is('api/*')) {
                // Set locale based on user's language for proper translation
                App::setLocale($userLanguage);
                
                return response()->json([
                    'success' => false,
                    'message' => __('Your account is not associated with any active organization. Please contact support.')
                ], 403);
            }
			 return redirect()->back()->withErrors(['email' => __('Your account is not associated with any active organization. Please contact support.',[],$userLanguage)])->withInput();
		}
        // Check TFA: user->tfa can be 1, '1', or true
        $userTfaEnabled = ($user->tfa == 1 || $user->tfa === '1' || $user->tfa === true);
        if ($userTfaEnabled && $addonActive == 1) {
            // Check if this is an API request (mobile)
            if ($request->expectsJson() || $request->is('api/*')) {
                // Set locale based on user's language for proper translation
                App::setLocale($userLanguage);
                
                return response()->json([
                    'success' => false,
                    'requires_tfa' => true,
                    'message' => __('Two-factor authentication required'),
                    'tfa_token' => encrypt($user->id . '|' . now()->timestamp) // Temporary token for TFA verification
                ], 200);
            }
            $request->session()->put('tfa', $user->id);
            $request->session()->put('remember', $remember);
            return redirect('/tfa');
        }

        if ($this->userVerificationService->verificationIsRequired($user)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'data' => [
						'requires_verification' => true,
						'verification_token' => $this->userVerificationService->createApiVerificationToken($user),
					],
                    'message' => __('verification.required'),
                ], 200);
            }

            $request->session()->put(UserVerificationService::SESSION_KEY, [
                'user_id' => $user->id,
                'remember' => (bool) $remember,
                'guard' => $user->role === 'admin' ? 'admin' : 'user',
            ]);

            return redirect('/verification-required');
        }
	
        return $this->doLogin($request, $user, $remember);
    }

    

    public function tfaVerify(TfaRequest $request)
    {
        // TfaRequest already validated everything, now we just need to get the user
        
        // Check if this is an API request (mobile)
        if ($request->expectsJson() || $request->is('api/*')) {
            // For mobile API, extract user from tfa_token
            $tfaToken = $request->input('tfa_token');
            $decrypted = decrypt($tfaToken);
            list($userId, $timestamp) = explode('|', $decrypted);
            $user = User::find($userId);
            $remember = false; // Mobile uses tokens, not remember me
            
            // Set locale based on user's language for proper translation
            App::setLocale($user->language ?? 'en');
        } else {
            // Web authentication
            $userId = $request->session()->get('tfa');
            $remember = $request->session()->get('remember');
            $user = User::find($userId);
        }

        if ($this->userVerificationService->verificationIsRequired($user)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'data' => [
						'requires_verification' => true,
						'verification_token' => $this->userVerificationService->createApiVerificationToken($user),
					],
                    'message' => __('verification.required'),
                ], 200);
            }

            $request->session()->put(UserVerificationService::SESSION_KEY, [
                'user_id' => $user->id,
                'remember' => (bool) ($remember ?? false),
                'guard' => $user->role === 'admin' ? 'admin' : 'user',
            ]);

            return redirect('/verification-required');
        }

        return $this->doLogin($request, $user, $remember ?? false);
    }

    private function doLogin(Request $request, $user, $remember)
    {
        if ($user->role === 'user') {
            $deviceCheckResponse = $this->validateDeviceAndRegister($request, $user);
            if ($deviceCheckResponse) {
                return $deviceCheckResponse;
            }
        }

	
        $guard = $user->role == 'user' ? 'user' : 'admin';
		
        if ($request->email || $request->password) {
            Auth::guard($guard)->attempt(['email' => $request->email, 'password' => $request->password], $remember);
        } else {
            Auth::guard($guard)->login($user, $remember);
        }
		/**
		 * @var User $user
		 */

        // Check if this is an API request (mobile)
        if ($request->expectsJson() || $request->is('api/*')) {
            // Set locale based on user's language for proper translation
            // $userLanguage = $user->language ?? 'en';
            // App::setLocale($userLanguage);
			if($request->has('device_token')){
				$user->syncDeviceTokens($request->get('device_token'),$request->get('device_name'),$request->get('device_type'));
			}
			
            // Revoke all existing tokens (optional - for security)
            // $user->tokens()->delete();
            
            // Create API token for mobile
            $tokenName = $request->device_name ?? 'mobile-app-' . now()->timestamp;
            $token = $user->createToken($tokenName)->plainTextToken;
            
            // Get user's organizations
            $organizations = [];
            $currentOrganizationId = null;
            if($guard == 'user'){
                $teams = Team::where('user_id', $user->id)->with('organization')->get();
                $organizations = $teams->map(function($team) {
                    return [
                        'id' => $team->organization_id,
                        'name' => $team->organization->name ?? '',
                        'role' => $team->role,
						'timezone' => DateTimeHelper::getCurrentTimeZone($team->organization_id),
                    ];
                })->toArray();
                
                // Set current organization only if user has exactly one organization
                if(count($organizations) == 1){
                    $currentOrganizationId = $organizations[0]['id'];
					$user->current_mobile_organization_id = $currentOrganizationId;
					$user->save();
                }
            }

            return response()->json([
                'success' => true,
                'message' => __('Login successful'),
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'language' => $user->language ?? 'ar',
                        'avatar' => $user->avatar,
                    ],
                    'token' => $token,
                   // 'token_type' => 'Bearer',
                    'organizations' => $organizations,
                    'current_organization_id' => $currentOrganizationId,
                ]
            ], 200);
        }

        // Web authentication (existing logic)
        //Check number of organizations
        if($guard == 'user'){
            $teams = Team::where('user_id', auth()->user()->id);
            if($teams->count() == 1){
                session()->put('current_organization', $teams->first()->organization_id);
            }
        }

        // Check if user's language differs from session locale and add refresh parameter
        $userLanguage = $user->language ?? 'en';
        $sessionLocale = session('locale', 'en');
        $needsRefresh = $userLanguage !== $sessionLocale;
        
        $redirectUrl = $user->role == 'user' ? '/dashboard' : 'admin/dashboard';
        if ($needsRefresh) {
            $redirectUrl .= '?refresh_lang=1';
        }
        return redirect($redirectUrl);
    }

    public function handleLogin(StoreUser $request)
    {

        $user = $this->userService->store($request);
        $authService = (new AuthService($user))->authenticateSession($request);

        return redirect('/dashboard');
    }

    public function socialLogin(Request $request, $type){
        if($type === 'google'){
            return SocialLoginService::makeGoogleDriver()->redirect();
        } else if($type === 'facebook'){
            //return Socialite::driver('facebook')->redirect();
            return SocialLoginService::makeFacebookDriver()->redirect();
        }
    }

    public function handleFacebookCallback(Request $request){
        if ($request->has('error')) {
            return Redirect::route('login')->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('There was an error with Facebook login!')
                ]
            );
        }

        try {
            $facebookUser = SocialLoginService::makeFacebookDriver()->fields(['id', 'name', 'first_name', 'last_name', 'email', 'gender', 'verified'])->user();
            $user = User::where('facebook_id', $facebookUser->id)->where('status', '=', '1')->where('deleted_at', null)->first();

            if ($user) {
                $deviceCheckResponse = $this->validateDeviceAndRegister($request, $user);
                if ($deviceCheckResponse) {
                    return $deviceCheckResponse;
                }

                if($user->role == 'user'){
                    //Check if user belongs to organization, otherwise set one up
                    $team = Team::where('user_id', $user->id)->first();

                    if(!$team){
                        //Create Organization
                        $organization = $this->createOrganization($user);

                        session()->put('current_organization', $organization->id);
                    }
                }

                $guard = $user->role == 'admin' ? 'admin' : 'user';
                Auth::guard($guard)->login($user);

                return redirect($user->role == 'admin' ? 'admin/dashboard' : '/dashboard');
            } else {
                DB::transaction(function () use ($facebookUser, $request) {
                    // Check if the email exists and handle accordingly
                    $user = User::where('email', $facebookUser->email)->first();

                    if ($user) {
                        // Link the Facebook ID to the existing user
                        $user->facebook_id = $facebookUser->id;
                        $user->save();

                        //Check if user belongs to organization, otherwise set one up
                        $team = Team::where('user_id', $user->id)->first();

                        if(!$team){
                            //Create Organization
                            $organization = $this->createOrganization($user);

                            session()->put('current_organization', $organization->id);
                        }
                    } else {
                        // Extract first name and last name
                        $nameParts = explode(' ', $facebookUser->name);
                        $firstName = $nameParts[0];
                        $lastName = isset($nameParts[1]) ? $nameParts[1] : '';

                        // Create User
                        $user = new User();
                        $user->first_name = $firstName;
                        $user->last_name = $lastName;
                        $user->email = $facebookUser->email;
                        $user->facebook_id = $facebookUser->id;
                        $user->password = null;
                        $user->email_verified_at = now();
                        $user->role = 'user';
                        $user->save();
                
                        //Create Organization
                        $organization = $this->createOrganization($user);
                
                        // Send Registration Email
                        Email::send('Registration', $user);

                        if (isset($config->value) && $config->value == '1') {
                            $user->sendEmailVerificationNotification();
                        }

                        session()->put('current_organization', $organization->id);
                    }
                    
                    // Register first social-login device (new account)
                    if (!$user->device) {
                        $this->userDeviceService->registerOrTouch($user, $this->userDeviceService->extractDeviceData($request));
                    }

                    // Log the user in
                    Auth::guard('user')->login($user, true);
                });
            
                return redirect('dashboard');
            }
        } catch (\Exception $e) {
            // Handle exception, possibly log the error and redirect to an error page
            Log::error('User registration failed: ' . $e->getMessage());
        
            return redirect()->back()->with('error', 'Registration failed, please try again.');
        }
    }

    public function googleCallback(Request $request){
        if ($request->has('error')) {
            return Redirect::route('login')->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('There was an error with Google login!')
                ]
            );
        }

        try {
            $gUser = SocialLoginService::makeGoogleDriver()->user();

            $user = User::where('email', $gUser->email)->where('status', '=', '1')->where('deleted_at', null)->first();

            if ($user) {
                $deviceCheckResponse = $this->validateDeviceAndRegister($request, $user);
                if ($deviceCheckResponse) {
                    return $deviceCheckResponse;
                }

                $guard = $user->role == 'admin' ? 'admin' : 'user';
                Auth::guard($guard)->login($user);

                return redirect($user->role == 'admin' ? 'admin/dashboard' : '/dashboard');
            } else {
                //Create User
                $name = explode(" ", $gUser->user['name']);

                $user = new User();
                $user->first_name = $name[0];
                $user->last_name = isset($name[1]) ? $name[1] : NULL;
                $user->email = $gUser->email;
                $user->password = NULL;
                $user->email_verified_at = now();
                $user->role = 'user';
                $user->save();

                $timestamp = now()->format('YmdHis');
                $randomString = Str::random(4);

                //Create Organization
                $organization = Organization::create([
                    'identifier' => $timestamp . $user->id . $randomString,
                    'name' => $name[0] . "'s organization",
                    'created_by' => $user->id
                ]);

                //Create Team
                $team = Team::create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                    'status' => 'active',
                    'created_by' => $user->id
                ]);

                $config = Setting::where('key', 'trial_period')->first();
                $has_trial = isset($config->value) && $config->value > 0 ? true : false;

                //Create Subscription
                Subscription::create([
                    'organization_id' => $organization->id,
                    'status' => $has_trial ? 'trial' : 'active',
                    'plan_id' => null,
                    'start_date' => now(),
                    'valid_until' => $has_trial ? date('Y-m-d H:i:s', strtotime('+' . $config->value . ' days')) : now(),
                ]);

                Email::send('Registration', $user);

                if (isset($config->value) && $config->value == '1') {
                    $user->sendEmailVerificationNotification();
                }

                if (!$user->device) {
                    $this->userDeviceService->registerOrTouch($user, $this->userDeviceService->extractDeviceData($request));
                }

                Auth::guard('user')->login($user, true);
                
                return redirect('dashboard');
            }
        } catch (\Exception $e) {
            // Handle exception, possibly log the error and redirect to an error page
            Log::error('User registration failed: ' . $e->getMessage());
        
            return redirect()->back()->with('error', 'Registration failed, please try again.');
        }
    }

    private function createOrganization($user){
        $timestamp = now()->format('YmdHis');
        $randomString = Str::random(4);

        // Create Organization
        $organization = Organization::create([
            'identifier' => $timestamp . $user->id . $randomString,
            'name' => $user->first_name . "'s organization",
            'created_by' => $user->id
        ]);

        // Create Team
        $team = Team::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'created_by' => $user->id
        ]);

        $config = Setting::where('key', 'trial_period')->first();
        $has_trial = isset($config->value) && $config->value > 0 ? true : false;

        // Create Subscription
        Subscription::create([
            'organization_id' => $organization->id,
            'status' => $has_trial ? 'trial' : 'active',
            'plan_id' => null,
            'start_date' => now(),
            'valid_until' => $has_trial ? date('Y-m-d H:i:s', strtotime('+' . $config->value . ' days')) : now(),
        ]);

        return $organization;
    }

    public function showRegistrationForm()
    {
        $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period', 'allow_facebook_login', 'allow_google_login'];
        $data['companyConfig'] = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();

        return Inertia::render('Auth/Register', $data);
    }

    public function handleRegistration(SignupRequest $request)
    {
        $user = $this->userService->store($request);
        $authService = (new AuthService($user))->authenticateSession($request);
        $config = Setting::where('key', 'verify_email')->first();

        if (isset($config->value) && $config->value == '1') {
            $user->sendEmailVerificationNotification();
        }

        return redirect('/dashboard');
    }

    public function viewInvite($uuid)
    {
        $invite = TeamInvite::where('code', $uuid)->first();

        if(!$invite){
            return Redirect::route('login')->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('That page does not exist!')
                ]
            );
        } else {
            $data['organization'] = Organization::where('id', $invite->organization_id)->first();
            $data['user'] = User::where('email', $invite->email)->where('role', 'user')->first();
            $data['invite'] = $invite;
            $data['code'] = $uuid;

            $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period', 'allow_facebook_login', 'allow_google_login'];
            $data['companyConfig'] = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();

            return Inertia::render('Auth/Invite', $data);
        }
    }

    public function invite(StoreUserInvite $request, $inviteCode)
    {
        (new TeamService)->store($request, $inviteCode);

        return Redirect::route('dashboard');
    }

    public function showForgotForm(Request $request)
    {
        $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period'];
        $data['companyConfig'] = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();

        return Inertia::render('Auth/Forgot', $data);
    }

    public function createPasswordResetToken(PasswordResetRequest $request)
    {
        (new PasswordResetService)->generateResetLink($request->input('email'));

        return redirect('/forgot-password')->with(
            'status', [
                'type' => 'success', 
                'message' => __('We\'ve sent you a password reset link to your email!')
            ]
        );
    }

    public function showResetForm(Request $request)
    {
        $email = $request->input('email');
        $token = $request->input('token');

        if(!(new PasswordResetService)->verifyResetCode($email, $token)){
            return redirect('/login');
        }

        $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period'];
        $data['companyConfig'] = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();

        return Inertia::render('Auth/Reset', $data);
    }

    public function resetPassword(PasswordValidateResetRequest $request)
    {
        (new PasswordResetService)->resetPassword($request);

        return redirect('/login')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Password reset successful!')
            ]
        );
    }

    public function showResetDevicesForm()
    {
        $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period'];
        $data['companyConfig'] = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();

        return Inertia::render('Auth/ResetDevices', $data);
    }

    public function sendResetDevicesLink(ResetDevicesRequest $request)
    {
        $email = $request->validated('email');
        $user = User::where('email', $email)
            ->whereNull('deleted_at')
            ->where('role', 'user')
            ->first();

        if ($user) {
            $smtpActive = Setting::where('key', 'smtp_email_active')->value('value');
            if ((string) $smtpActive === '1') {
                $companyName = (string) (Setting::where('key', 'company_name')->value('value') ?? config('app.name'));
                $logo = Setting::where('key', 'logo')->value('value');
                $logoUrl = $logo ? url('/media/'.ltrim($logo, '/')) : '';

                $signedUrl = URL::temporarySignedRoute(
                    'auth.reset-devices',
                    now()->addMinutes(30),
                    ['user' => $user->getKey()]
                );

                try {
                    Mail::to($user->email)->queue(new ResetLinkedDevicesMail($user, $signedUrl, $logoUrl, $companyName));
                } catch (\Throwable $e) {
                    Log::error('Reset linked devices email failed: '.$e->getMessage(), ['email' => $user->email]);
                }
            } else {
                Log::warning('Reset linked devices: SMTP not active, email not sent', ['email' => $user->email]);
            }
        }

        return redirect()->back()->with(
            'status',
            [
                'type' => 'success',
                'message' => __('If the email exists, a reset link has been sent.'),
            ]
        );
    }

    public function processResetDevices(Request $request, User $user)
    {
        if ($user->role !== 'user' || $user->trashed()) {
            abort(404);
        }

        UserDevice::where('user_id', $user->id)->delete();

        $user->tokens()->delete();

        if (config('session.driver') === 'database' && Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return redirect('/login')->with(
            'status',
            [
                'type' => 'success',
                'message' => __('Devices reset successfully. You can now log in from any device.'),
            ]
        );
    }

    public function verifyEmail()
    {
        if(auth()->user()->email_verified_at != NULL){
            return redirect('dashboard');
        } else {
            $keys = ['logo', 'company_name', 'address', 'email', 'phone', 'socials', 'trial_period'];
            $data['companyConfig'] = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();

            return Inertia::render('Auth/VerifyEmail', $data);
        }
    }

    public function sendEmailVerification(Request $request)
    {
        $request->user()->sendEmailVerificationNotification();

        return back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Verification link sent!')
            ]
        );
    }

    public function logout(Request $request)
    {
        // Check if this is an API request (mobile)
        if ($request->expectsJson() || $request->is('api/*')) {
            // Set locale based on user's language for proper translation
            if ($request->user()) {
                App::setLocale($request->user()->language ?? 'en');
                // Revoke the current token. Clear ONLY the mobile column
                // — a web session running in parallel must keep its own
                // current_web_organization_id intact.
				$request->user()->current_mobile_organization_id = null;
				$request->user()->save();
                // Delete all tokens for the user (more reliable than currentAccessToken in tests)
                $request->user()->tokens()->delete();
            }
            
            return response()->json([
                'success' => true,
                'message' => __('Logged out successfully')
            ], 200);
        }
        
        // Web authentication
        Auth::guard('user')->logout();
        Session::flush();

        return redirect('login');
    }
	public function setCurrentOrganization(UserHasOrganizationRequest $request)
	{
		$organization = Organization::find($request->organization_id);
		if(!$organization){
			return response()->json([
				'success' => false,
				'message' => __('Organization not found')
			], 404);
		}

		$context = app(\App\Services\OrganizationContextService::class);
		// UserHasOrganizationRequest already enforces membership via
		// OrganizationHasUserRule, but setCurrent() re-checks and ALSO
		// rejects banned/soft-deleted organizations.
		if (!$context->setCurrent($request->user(), $organization->id)) {
			return response()->json([
				'success' => false,
				'message' => __('The selected organization is not associated with the user.')
			], 403);
		}

		return response()->json([
			'success' => true,
			'message' => __('Organization set successfully'),
			'data' => [
				'current_organization_id' => $organization->id,
			],
		], 200);
	}

    public function leaveCurrentOrganization(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('Unauthenticated'),
            ], 401);
        }

        $context = app(OrganizationContextService::class);
        $platform = OrganizationContextService::PLATFORM_MOBILE;
        $organizationId = (int) ($user->current_mobile_organization_id ?? 0);

        if ($organizationId <= 0) {
            return response()->json([
                'success' => false,
                'message' => __('No organization is selected.'),
            ], 422);
        }

        $detach = $context->detachMembership($user, $organizationId);
        if (!$detach['ok']) {
            return response()->json([
                'success' => false,
                'message' => $detach['message'],
            ], (int) ($detach['status'] ?? 400));
        }

        $user->refresh();
        $context->ensureValid($user, $platform);
        $user->refresh();

        if ($user->canNotAccessDashboard()) {
            $user->current_mobile_organization_id = null;
            $user->save();
            $user->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => __('You have left the organization. Your session has been closed.'),
                'data' => [
                    'current_organization_id' => null,
                ],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => __('You have left this organization.'),
            'data' => [
                'current_organization_id' => (int) $user->current_mobile_organization_id,
            ],
        ], 200);
    }

    public function deleteAccount(Request $request, UserAccountDeletionService $userAccountDeletionService)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('Unauthenticated'),
            ], 401);
        }

        $result = $userAccountDeletionService->softDeleteDashboardUser($user);
        if (!$result['ok']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], (int) ($result['status'] ?? 400));
        }

        return response()->json([
            'success' => true,
            'message' => __('Your account has been deleted.'),
        ], 200);
    }

    private function validateDeviceAndRegister(Request $request, User $user)
    {
        $deviceData = $this->userDeviceService->extractDeviceData($request);
        $existingDevice = $user->device;

        if (!$existingDevice) {
            $this->userDeviceService->registerOrTouch($user, $deviceData);
            return null;
        }

        if (!$this->userDeviceService->matches($existingDevice, $deviceData)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => __('This account is already linked to another device. Please remove it first from settings.'),
                ], 403);
            }

            return redirect()->back()->withErrors([
                'email' => __('This account is already linked to another device. Please remove it first from settings.', [], $user->language ?? 'en'),
            ])->withInput()->with('show_reset_devices_link', true);
        }

        $this->userDeviceService->registerOrTouch($user, $deviceData);
        return null;
    }
}
