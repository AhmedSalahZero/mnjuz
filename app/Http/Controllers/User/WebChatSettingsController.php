<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\ChannelAccount;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Services\WebChat\WebChatAssets;
use App\Services\WebChat\WebChatSettings;
use App\Services\WebChat\WebChatWidgetService;
use App\Support\OrganizationRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * إعدادات المحادثة المباشرة: الكود الذي يُلصق في موقع التاجر، وشكل الـ widget،
 * ونموذج الزائر، والمواقع المسموحة.
 *
 * محروسة كساعات العمل: الإضافة «Web Chat» في الباقة، والموظّف (agent) لا
 * يدخلها. الـ widget يُنشأ عند أول زيارة: لا شيء يُلصق قبلها، فلا ضرر.
 */
class WebChatSettingsController extends Controller
{
    private const LOGO_DIRECTORY = 'webchat-logos';

    public function __construct(private readonly WebChatWidgetService $widgets)
    {
    }

    public function show(Request $request)
    {
        $account = $this->widget();

        return Inertia::render('User/Settings/WebChat', [
            'title' => __('Web Chat'),
            'modules' => Addon::get(),
            'widget' => $this->view($account),
        ]);
    }

    public function update(Request $request)
    {
        $account = $this->widget();

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'allowed_domains' => ['nullable', 'array', 'max:50'],
            'allowed_domains.*' => ['string', 'max:255'],
            'appearance.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.position' => ['required', Rule::in(WebChatSettings::POSITIONS)],
            'appearance.offset_x' => ['required', 'integer', 'between:0,200'],
            'appearance.offset_y' => ['required', 'integer', 'between:0,200'],
            'appearance.title' => ['nullable', 'string', 'max:80'],
            'appearance.subtitle' => ['nullable', 'string', 'max:80'],
            'appearance.welcome_message' => ['nullable', 'string', 'max:500'],
            'appearance.language' => ['required', Rule::in(WebChatSettings::LANGUAGES)],
            'prechat.default_country' => ['required', 'string', 'size:2', 'alpha'],
            'prechat.email' => ['required', Rule::in(WebChatSettings::EMAIL_MODES)],
            'prechat.name_label' => ['nullable', 'string', 'max:60'],
            'prechat.phone_label' => ['nullable', 'string', 'max:60'],
            'prechat.consent_text' => ['nullable', 'string', 'max:500'],
            'behavior.show_agent_names' => ['required', 'boolean'],
            'behavior.sound' => ['required', 'boolean'],
            'behavior.uploads' => ['required', 'boolean'],
        ]);

        $current = WebChatSettings::for($account);

        $account->forceFill(['settings' => WebChatSettings::merge([
            'enabled' => (bool) $validated['enabled'],
            'allowed_domains' => array_values((array) ($validated['allowed_domains'] ?? [])),
            // الشعار له مساره الخاص (uploadLogo)، فلا يمحوه حفظ النموذج.
            'appearance' => array_replace($validated['appearance'], [
                'logo_url' => $current['appearance']['logo_url'],
                'logo_path' => $current['appearance']['logo_path'],
            ]),
            'prechat' => $validated['prechat'],
            'behavior' => $validated['behavior'],
        ])])->save();

        return back()->with('status', ['type' => 'success', 'message' => __('Settings updated successfully')]);
    }

    /** شعار الـ widget: صورة صغيرة تُخزَّن عندنا ويُحسب رابطها عند العرض. */
    public function uploadLogo(Request $request)
    {
        $account = $this->widget();

        $request->validate([
            // لا SVG: يُخدم من نطاقنا ويحمل سكربتاً.
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        ]);

        $file = $request->file('logo');
        $name = strtolower((string) Str::ulid()) . '.' . $file->extension();
        $settings = WebChatSettings::for($account);

        if (Setting::where('key', 'storage_system')->value('value') === 'aws') {
            $path = $file->storeAs(self::LOGO_DIRECTORY . '/' . $account->organization_id, $name, 's3');
            $settings['appearance']['logo_url'] = $path ? Storage::disk('s3')->url($path) : null;
            $settings['appearance']['logo_path'] = null;
        } else {
            $path = $file->storeAs('public/' . self::LOGO_DIRECTORY . '/' . $account->organization_id, $name, 'local');
            $settings['appearance']['logo_path'] = $path ?: null;
            $settings['appearance']['logo_url'] = null;
        }

        $this->deleteLocalLogo(WebChatSettings::for($account)['appearance']['logo_path']);
        $account->forceFill(['settings' => WebChatSettings::merge($settings)])->save();

        return back()->with('status', ['type' => 'success', 'message' => __('Settings updated successfully')]);
    }

    public function removeLogo()
    {
        $account = $this->widget();
        $settings = WebChatSettings::for($account);

        $this->deleteLocalLogo($settings['appearance']['logo_path']);
        $settings['appearance']['logo_path'] = null;
        $settings['appearance']['logo_url'] = null;

        $account->forceFill(['settings' => WebChatSettings::merge($settings)])->save();

        return back()->with('status', ['type' => 'success', 'message' => __('Settings updated successfully')]);
    }

    /**
     * مفتاح جديد. الكود القديم في موقع التاجر يتوقّف فوراً — الواجهة تُحذّر
     * وتطلب التأكيد قبل الوصول إلى هنا.
     */
    public function rotateKey()
    {
        $this->widgets->rotateKey($this->widget());

        return back()->with('status', [
            'type' => 'success',
            'message' => __('A new key was generated. Replace the code on your website.'),
        ]);
    }

    /**
     * صفحة فارغة فيها الـ widget — تعرضها صفحة الإعدادات في iframe. تعمل
     * والـ widget معطَّل (ResolveWebChatWidget::isPreview)، والمحادثة فيها
     * حقيقية: يرى التاجر الردّ الآلي ويصله في صندوقه.
     */
    public function preview()
    {
        $account = $this->widget();
        $rtl = app()->getLocale() === 'ar';

        $html = '<!doctype html><html lang="' . e(app()->getLocale()) . '" dir="' . ($rtl ? 'rtl' : 'ltr') . '">'
            . '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e(__('Web Chat')) . '</title>'
            . '<style>html,body{margin:0;height:100%;background:#f1f5f9;font-family:system-ui,sans-serif}</style>'
            . '</head><body>'
            . self::snippet((string) $account->external_id, true)
            . '</body></html>';

        return response($html)->header('Cache-Control', 'no-store');
    }

    /* ───────────────────────── مساعدات ───────────────────────── */

    private function widget(): ChannelAccount
    {
        $organizationId = (int) session()->get('current_organization');

        if (!$organizationId || !WebChatWidgetService::moduleEnabled($organizationId)) {
            abort(403);
        }

        $role = Team::where('organization_id', $organizationId)->where('user_id', auth()->id())->value('role');

        if (OrganizationRole::isAgent($role)) {
            abort(403);
        }

        return $this->widgets->forOrganization($organizationId)
            ?? $this->widgets->ensureForOrganization(
                $organizationId,
                (string) (Organization::where('id', $organizationId)->value('name') ?? ''),
                (int) auth()->id()
            );
    }

    private function view(ChannelAccount $account): array
    {
        $settings = WebChatSettings::for($account);
        $key = (string) $account->external_id;

        return [
            'key' => $key,
            'snippet' => self::snippet($key),
            'settings' => $settings,
            'logo' => WebChatSettings::publicView($account)['appearance']['logo_url'],
            'preview_url' => url('/settings/webchat/preview'),
        ];
    }

    public static function snippet(string $key, bool $preview = false): string
    {
        return '<script async src="' . e(WebChatAssets::loaderUrl()) . '" data-mnjz-key="' . e($key) . '"'
            . ($preview ? ' data-mnjz-preview="1"' : '')
            . '></script>';
    }

    private function deleteLocalLogo(?string $path): void
    {
        if ($path && str_starts_with($path, 'public/' . self::LOGO_DIRECTORY . '/')) {
            Storage::disk('local')->delete($path);
        }
    }
}
