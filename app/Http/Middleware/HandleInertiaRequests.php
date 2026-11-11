<?php

namespace App\Http\Middleware;

use App\Helpers\CustomHelper;
use App\Models\Addon;
use App\Models\Chat;
use App\Models\Language;
use App\Models\Organization;
use App\Models\Setting;
use App\Services\Broadcasting\BroadcastProvider;
use App\Models\Team;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Inertia\Middleware;

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
        $user = $request->user('admin') ?? $request->user();
        
        // Set locale based on user's language if authenticated
        if ($user && $user->language) {
            app()->setLocale(strtolower($user->language));
            // Also set session locale to persist across requests
            session(['locale' => strtolower($user->language)]);
        } elseif (session()->has('locale')) {
            app()->setLocale(strtolower(session('locale')));
        }
        
        $language = app()->getLocale();
        $unreadMessages = fn () => 0;

        // ما يقرأ القاعدة يُغلَّف في closure: Inertia لا تستدعيها إلا حين تبني
        // صفحة تطلبها. فتح محادثة طلبٌ جزئي (only: contact, chatThread, …)،
        // ونبض الحضور وتعليم المقروء طلبات JSON — وكلها كانت تدفع نحو عشرين
        // استعلاماً هنا ثم تُرمى. التحميل الكامل يحسبها كلها كما كان.
        $tfaActive = fn () => $user
            ? Addon::where('name', 'Google Authenticator')->first()->is_active == 1
            : false;

        if ($user && $user->role === 'user') {
            $organizationId = session('current_organization');
            // يبقى مباشراً: ما بعده في الطلب قد يقرأ $user->teams مقصورةً على
            // المنشأة الحالية، فتأجيله يغيّر ما يراه المتحكّم.
            $user->load(['teams' => function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            }]);

            // Only columns used in shared UI: switcher (uuid, name), Pusher (id), Menu/Profile (name, address), App/Dashboard (metadata)
            $organizations = fn () => Team::with('organization:id,uuid,name')
                ->where('user_id', $user->id)
                ->get();
            $organization = fn () => $this->organizationProp($organizationId);

            // العدّ مؤجَّل عمداً: Inertia تستدعي share() قبل المتحكّم، وفتح
            // محادثة يعلّم رسائلها مقروءة داخله. الحساب هنا مباشرةً كان يُرجع
            // عدد ما قبل القراءة، فيكتب فوق النقصان المتفائل في الواجهة ويبقى
            // العدّاد ثابتاً حتى الطلب التالي. الإغلاق يُنفَّذ عند بناء الردّ.
            $unreadMessages = fn () => Chat::where('organization_id', $organizationId)
                ->where('type', 'inbound')
                ->where('deleted_at', NULL)
                ->where('is_read', 0)
                ->count();
        }

        if($this->isInstalled()){
            $keys = [
                'favicon', 'logo', 'company_name', 'address', 'currency', 'email', 'phone', 'socials',
                'trial_period', 'recaptcha_site_key', 'recaptcha_active', 'google_maps_api_key',
                'pusher_app_key', 'pusher_app_cluster', 'google_auth_active',
                'allow_facebook_login', 'allow_google_login',
            ];
            $config = fn () => Setting::whereIn('key', $keys)->get();
            // Only columns used in shared UI: LangToggle/ProfileModal (code, name), dropdowns (id for key)
            $languages = fn () => Language::where('deleted_at', null)
                ->where('status', 'active')
                ->get(['id', 'code', 'name']);
            $isRtl = function () use ($language) {
                $currentLanguage = Language::where('code', $language)->first(['is_rtl']);

                return $currentLanguage ? (bool) $currentLanguage->is_rtl : false;
            };
        } else {
            $config = array();
            $languages = array();
            $isRtl = false;
        }

        // Only fields used in shared UI: Menu/Profile (name, email, phone, language), Dashboard (first_name, teams[].role), TicketTable (role)
        $authUser = null;
        if ($user) {
            $organizationTeamRole = null;
            if ($user->role === 'user' && $user->relationLoaded('teams') && $user->teams->isNotEmpty()) {
                $organizationTeamRole = $user->teams->first()->role;
            }
            $authUser = [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'full_name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'email' => $user->email,
                'phone' => $user->phone,
                'language' => $user->language ?? 'en',
                'verification_enabled' => (bool) ($user->verification_enabled ?? false),
                'is_verified' => (bool) ($user->is_verified ?? false),
                'role' => $user->role,
                'organization_team_role' => $organizationTeamRole,
                'teams' => $user->relationLoaded('teams')
                    ? $user->teams->map(fn ($t) => ['id' => $t->id, 'role' => $t->role, 'organization_id' => $t->organization_id])->values()->toArray()
                    : [],
            ];
        }
        // قراءة الـ flash ثم حذفه فوراً حتى لا يبقى في الجلسة بعد الطلب (مهم عند استخدام router.visit مع only لأن الطلب التالي قد يكون جزئياً)
        $message = session('status');
        session()->forget('status');
        $showResetDevicesLink = session()->pull('show_reset_devices_link', false);

        $settingsModuleWorkingHours = false;
        $settingsModuleWebChat = false;
        if ($user && ($user->role ?? null) === 'user' && session()->has('current_organization')) {
            $currentOrganization = (int) session('current_organization');
            $settingsModuleWorkingHours = fn () => CustomHelper::isModuleEnabled('Working Hours', $currentOrganization);
            // متخبَّأ دقيقة: يُسأل مع كل طلب Inertia.
            $settingsModuleWebChat = fn () => \App\Services\WebChat\WebChatWidgetService::moduleEnabled($currentOrganization);
        }

        $broadcastOrganization = session('current_organization');

        return array_merge(parent::share($request), [
            'csrf_token' => csrf_token(),
            'applicationVersion' => fn () => Config::get('version.version'),
            'applicationReleaseDate' => fn () => Config::get('version.release_date'),
            'config' => $config,
            // إعداد البثّ للعميل — يتبع مفتاح التبديل بلا إعادة بناء.
            // مزوّد المنشأة الحالية؛ صفحات الأدمن بلا منشأة فتأخذ الافتراضي.
            'broadcast' => fn () => BroadcastProvider::clientConfig((int) $broadcastOrganization ?: null),
            // أقصى حجم يقبله الخادم فعلاً. الملف الأكبر يرفضه PHP قبل بلوغ
            // Laravel، فتصل حمولة بلا ملف ويظنّ المستخدم أن الإرسال نجح.
            // نمرّره للواجهة لتردّ الملف مبكّراً برسالة تذكر الحدّ الحقيقي.
            'max_upload_bytes' => \App\Helpers\ChatMediaUploadHelper::phpMaxUploadBytes(),
            // سقف الطلب الواحد: مجموع الدفعة يُقاس به لا بسقف الملف. وهو أصغر
            // ما بين حدّ PHP وحدّ الوكيل الأمامي — والثاني غير مرئي لـPHP.
            'max_post_bytes' => \App\Helpers\ChatMediaUploadHelper::maxRequestBytes(),
            // حجم قطعة الرفع المجزّأ الذي يقبله هذا الخادم: 5MB ثابتة كانت
            // تُرفض كلّها حيث upload_max_filesize أصغر منها (2MB افتراضياً).
            'upload_chunk_bytes' => \App\Helpers\ChatMediaUploadHelper::chunkBytes(),
            'admin_organization_impersonation' => (bool) session('admin_org_impersonation', false),
            'admin_impersonation_org_name' => session('admin_impersonation_org_name'),
            'auth' => [
                'user' => $authUser,
            ],
            'organization' => $organization,
            'organizations' => $organizations,
            'flash' => [
                'status'=> $message,
                'show_reset_devices_link' => (bool) $showResetDevicesLink,
            ],
            'refresh_lang' => session('refresh_lang', false),
            'response_data' => fn () => $request->session()->get('response_data'),
            'languages' => $languages,
            'unreadMessages' => $unreadMessages,
            'currentLanguage' => $language,
            'tfa' => fn () => [
                'status' => $tfaActive(),
                'enabled' => $user ? $user->tfa : false,
            ],
            'isRtl' => $isRtl,
            'settingsModuleWorkingHours' => $settingsModuleWorkingHours,
            'settingsModuleWebChat' => $settingsModuleWebChat,
        ]);
    }

    /**
     * المنشأة الحالية كما تعرضها الواجهة المشتركة (القائمة، الملف، مزايا الباقة).
     *
     * @return array<string, mixed>|null
     */
    private function organizationProp($organizationId): ?array
    {
        $organization = Organization::where('id', $organizationId)
            ->first(['id', 'uuid', 'name', 'metadata', 'address']);

        if (!$organization) {
            return null;
        }

        $organizationArray = $organization->toArray();
        $meta = json_decode($organization->metadata ?? '{}', true) ?: [];

        $organizationArray['notifications'] = $meta['notifications'] ?? null;
        $organizationArray['notification'] = $meta['notification'] ?? null;
        $organizationArray['plan'] = [
            'features' => [
                'ice_breakers' => SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'ice_breakers'),
                'shortcuts' => SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'shortcuts'),
                'agent_performance' => SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'agent_performance'),
                'activity_log' => SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'activity_log'),
                'rating_delete' => SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'rating_delete'),
            ],
        ];

        unset($organizationArray['metadata']);

        return $organizationArray;
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
