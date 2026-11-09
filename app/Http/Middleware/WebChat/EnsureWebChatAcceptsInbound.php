<?php

namespace App\Http\Middleware\WebChat;

use App\Services\WebChat\WebChatWidgetService;
use Closure;
use Illuminate\Http\Request;

/**
 * لا رسائل جديدة لمنشأة انتهى اشتراكها أو بلغت حدّ الرسائل الواردة —
 * نفس فحص webhook القنوات الأخرى (ChannelWebhookController).
 */
class EnsureWebChatAcceptsInbound
{
    public function handle(Request $request, Closure $next)
    {
        $account = $request->attributes->get(ResolveWebChatWidget::ATTRIBUTE);

        if (!WebChatWidgetService::acceptsInbound((int) $account->organization_id)) {
            return response()->json(['error' => 'unavailable'], 403);
        }

        return $next($request);
    }
}
