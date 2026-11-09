<?php

namespace App\Http\Middleware\WebChat;

use App\Models\WebChatVisitor;
use Closure;
use Illuminate\Http\Request;

/**
 * الزائر صاحب التوكن على هذا الـ widget.
 *
 * التوكن في ترويسة خاصّة لا في Authorization: تلك يلتقطها حارس sanctum
 * فيحاول تفسيرها توكن مستخدم. ولا كوكي: الـ widget يعمل داخل موقع غيرنا،
 * والكوكي هناك طرف ثالث يحجبه المتصفّح.
 */
class AuthenticateWebChatVisitor
{
    public const HEADER = 'X-Webchat-Token';
    public const ATTRIBUTE = 'webchat.visitor';

    public function handle(Request $request, Closure $next)
    {
        $account = $request->attributes->get(ResolveWebChatWidget::ATTRIBUTE);
        $visitor = WebChatVisitor::findByToken($account, (string) $request->headers->get(self::HEADER, ''));

        if (!$visitor) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        // آخر ظهور بدقّة دقيقة: الكتابة مع كل طلب تُثقل بلا فائدة.
        if (!$visitor->last_seen_at || $visitor->last_seen_at->lt(now()->subMinute())) {
            $visitor->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        $request->attributes->set(self::ATTRIBUTE, $visitor);

        return $next($request);
    }
}
