<?php

namespace App\Http\Middleware;

use App\Models\Addon;
use App\Models\Chat;
use App\Models\Language;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Middleware;
use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;
use Illuminate\Support\Facades\App;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Defines the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function share(Request $request): array
    {
        $organization = array();
        $organizations = array();
        $user = $request->user();
        $language = session('locale') ?? 'en';
        $unreadMessages = 0;
        $secret = '';
        $qrcode = '';
        $tfaActive = false;

        // two-factor stuff
        if ($user) {
            $googleAuth = Addon::where('name', 'Google Authenticator')->first()->is_active;
            $tfaActive = $googleAuth == 1 ? true : false;

            if($googleAuth == 1){
                $secret = $user->tfa_secret;
                $tfa = new TwoFactorAuth(new BaconQrCodeProvider());
        
                if (!$secret) {
                    $secret = $tfa->createSecret();
            
                    User::where('id', $user->id)->update([
                        'tfa_secret' => $secret,
                    ]);
                }
        
                $qrcode = $tfa->getQRCodeImageAsDataUri(preg_replace('#^https?://#', '', config('app.url')), $secret);
            }
        }

        if ($user && $user->role === 'user') {
            $organizationId = session('current_organization');
            $user->load(['teams' => function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            }]);

            $organizations = Team::with('organization')->where('user_id', $user->id)->get();
            $organization = Organization::where('id', $organizationId)->first();
            $unreadMessages = Chat::where('organization_id', $organizationId)
                ->where('type', 'inbound')
                ->where('deleted_at', NULL)
                ->where('is_read', 0)
                ->count();
        }

        if($this->isInstalled()){
            $keys = ['favicon', 'logo', 'company_name', 'address', 'currency' , 'email', 'phone', 'socials', 'trial_period', 'recaptcha_site_key', 'recaptcha_active', 'google_analytics_tracking_id', 'google_maps_api_key','pusher_app_key','pusher_app_cluster', 'google_auth_active', 'enable_api_key_input', 'enable_model_selection', 'default_open_ai_text_model', 'default_open_ai_audio_model'];
            $config = Setting::whereIn('key', $keys)->get();
            $languages = Language::where('deleted_at', null)
                               ->where('status', 'active')
                               ->get();
            $currentLanguage = Language::where('code', session('locale') ?? App::getLocale())->first();
            $isRtl = $currentLanguage ? $currentLanguage->is_rtl : false;
        } else {
            $config = array();
            $languages = array();
            $isRtl = false;
        }

        return array_merge(parent::share($request), [
            'csrf_token' => csrf_token(),
            'applicationVersion' => fn () => Config::get('version.version'),
            'applicationReleaseDate' => fn () => Config::get('version.release_date'),
            'config' => $config,
            'auth' => [
                'user' => $user ?: null,
            ],
            'organization' => $organization,
            'organizations' => $organizations,
            'flash' => [
                'status'=> session('status')
            ],
            'response_data' => fn () => $request->session()->get('response_data'),
            'languages' => $languages,
            'unreadMessages' => $unreadMessages,
            'currentLanguage' => $language,
            'tfa' => [
                'status' => $tfaActive,
                'secret' => $secret,
                'qrcode' => $qrcode,
                'enabled' => $user ? $user->tfa : false,
            ],
            'isRtl' => $isRtl,
        ]);
    }

    /**
     * Checks if the application has been installed.
     *
     * @return bool
     */
    public function isInstalled(): bool
    {
        return file_exists(storage_path('installed'));
    }
}
