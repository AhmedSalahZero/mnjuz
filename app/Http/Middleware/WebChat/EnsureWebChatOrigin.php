<?php

namespace App\Http\Middleware\WebChat;

use App\Services\WebChat\WebChatSettings;
use Closure;
use Illuminate\Http\Request;

/**
 * الـ widget يعمل على مواقع التاجر وحدها إن حدّدها.
 *
 * حماية من النسخ لا من الهجوم: من يرسل الطلب من خارج المتصفّح يكتب Origin
 * كما يشاء. الغرض أن لا يضع أحدٌ مفتاح تاجر في موقعه فتصل محادثاته إليه.
 * صفحة الإعدادات عندنا (المعاينة) مسموحة دائماً.
 */
class EnsureWebChatOrigin
{
    public function handle(Request $request, Closure $next)
    {
        $account = $request->attributes->get(ResolveWebChatWidget::ATTRIBUTE);
        $settings = WebChatSettings::for($account);

        if ($settings['allowed_domains'] === []) {
            return $next($request);
        }

        $host = self::requestHost($request);

        if ($host !== null && ($host === self::ownHost() || WebChatSettings::hostAllowed($settings, $host))) {
            return $next($request);
        }

        return response()->json(['error' => 'origin_not_allowed'], 403);
    }

    /** مضيف الصفحة التي طلبت: Origin أولاً، ثم Referer لطلبات GET البسيطة. */
    public static function requestHost(Request $request): ?string
    {
        foreach (['Origin', 'Referer'] as $header) {
            $value = (string) $request->headers->get($header, '');

            if ($value === '' || $value === 'null') {
                continue;
            }

            $host = parse_url($value, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                return WebChatSettings::normalizeDomain($host);
            }
        }

        return null;
    }

    private static function ownHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) ? WebChatSettings::normalizeDomain($host) : null;
    }
}
