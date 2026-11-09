<?php

namespace App\Http\Middleware\WebChat;

use App\Models\ChannelAccount;
use App\Services\Messaging\Channel;
use App\Services\WebChat\WebChatSettings;
use App\Services\WebChat\WebChatWidgetService;
use Closure;
use Illuminate\Http\Request;

/**
 * الـ widget صاحب المفتاح في المسار، أو 404.
 *
 * ردّ واحد لكل أسباب الرفض — مفتاح مجهول، widget موقوف، إضافة غير مفعّلة
 * في الباقة: لا نكشف لمن يجرّب المفاتيح أيّها موجود.
 */
class ResolveWebChatWidget
{
    public const ATTRIBUTE = 'webchat.account';

    public function handle(Request $request, Closure $next)
    {
        $key = (string) $request->route('key');

        if (!WebChatWidgetService::isWellFormedKey($key)) {
            return self::notFound();
        }

        $account = ChannelAccount::findForWebhook(Channel::WEBCHAT, $key);

        if (!$account
            || !$account->isActive()
            || (!WebChatSettings::for($account)['enabled'] && !self::isPreview($request))
            || !WebChatWidgetService::moduleEnabled((int) $account->organization_id)
        ) {
            return self::notFound();
        }

        $request->attributes->set(self::ATTRIBUTE, $account);

        return $next($request);
    }

    /**
     * معاينة صفحة الإعدادات: الـ widget المعطَّل يعمل فيها ليرى التاجر شكله
     * قبل تفعيله. من نطاقنا وحده — موقع التاجر لا يرى widget معطَّلاً.
     */
    public static function isPreview(Request $request): bool
    {
        $asked = $request->query('preview') === '1' || $request->headers->get('X-Webchat-Preview') === '1';

        if (!$asked) {
            return false;
        }

        return EnsureWebChatOrigin::isOwnHost($request, EnsureWebChatOrigin::requestHost($request));
    }

    public static function notFound()
    {
        return response()->json(['error' => 'not_found'], 404);
    }
}
