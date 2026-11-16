<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Controllers\User\Concerns\AuthorizesWebChatSettings;
use App\Models\Addon;
use App\Models\ChannelAccount;
use App\Services\WebChat\WebChatSettingsForm;
use App\Services\WebChat\WebChatWidgetService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * إعدادات المحادثة المباشرة: الكود الذي يُلصق في موقع التاجر، وشكل الـ widget،
 * ونموذج الزائر، والمواقع المسموحة.
 *
 * محروسة كساعات العمل: الإضافة «Web Chat» في الباقة، والموظّف (agent) لا
 * يدخلها. الـ widget يُنشأ عند أول زيارة: لا شيء يُلصق قبلها، فلا ضرر.
 *
 * القواعد والحفظ والشعار في WebChatSettingsForm — التطبيق يمرّ منها كذلك.
 */
class WebChatSettingsController extends Controller
{
    use AuthorizesWebChatSettings;

    public function __construct(
        private readonly WebChatWidgetService $widgets,
        private readonly WebChatSettingsForm $form,
    ) {
    }

    public function show(Request $request)
    {
        $account = $this->widget();

        return Inertia::render('User/Settings/WebChat', [
            'title' => __('Web Chat'),
            'modules' => Addon::get(),
            'widget' => $this->form->view($account),
        ]);
    }

    public function update(Request $request)
    {
        $account = $this->widget();

        $this->form->apply($account, $this->form->validator($request->all(), $account)->validate());

        return back()->with('status', ['type' => 'success', 'message' => __('Settings updated successfully')]);
    }

    /** شعار الـ widget: صورة صغيرة تُخزَّن عندنا ويُحسب رابطها عند العرض. */
    public function uploadLogo(Request $request)
    {
        $account = $this->widget();

        $request->validate($this->form->logoRules());

        $this->form->storeLogo($account, $request->file('logo'));

        return back()->with('status', ['type' => 'success', 'message' => __('Settings updated successfully')]);
    }

    public function removeLogo()
    {
        $this->form->removeLogo($this->widget());

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
        return $this->form->widget($this->webChatOrganizationId(), (int) auth()->id());
    }

    public static function snippet(string $key, bool $preview = false): string
    {
        return WebChatSettingsForm::snippet($key, $preview);
    }
}
