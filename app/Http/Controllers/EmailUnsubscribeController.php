<?php

namespace App\Http\Controllers;

use App\Models\EmailUnsubscribe;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

/**
 * إلغاء الاشتراك في بريد حملات منشأة — رابط في كل رسالة وترويسة
 * List-Unsubscribe (RFC 8058).
 *
 * الرابط موقَّع (APP_KEY) وهو التصريح وحده: بلا جلسة ولا CSRF، لأن مزوّدي
 * البريد (Gmail، Yahoo) يرسلون POST «بنقرة واحدة» من خوادمهم. وGET لا يغيّر
 * شيئاً — فاحصات الروابط في البريد تفتحه — بل يعرض زرّ التأكيد.
 *
 * الحدّ على التواقيع الخاطئة وحدها: طلبات مزوّد البريد تأتي من عناوين قليلة
 * مشتركة، وحدٌّ لكل عنوان كان سيُسقط إلغاءات حقيقية فتستمرّ الرسائل.
 */
class EmailUnsubscribeController extends Controller
{
    private const INVALID_ATTEMPTS_PER_MINUTE = 30;

    /** الرابط الموقَّع الذي يُوضع في الرسالة. بلا انتهاء: إلغاء الاشتراك لا يسقط بالتقادم. */
    public static function urlFor(Organization $organization, string $email, ?string $locale = null): string
    {
        return URL::signedRoute('email.unsubscribe', array_filter([
            'organization' => (string) $organization->uuid,
            'email' => EmailUnsubscribe::normalize($email),
            'lang' => $locale,
        ]));
    }

    public function show(Request $request, string $organization)
    {
        [$org, $email, $invalid] = $this->resolve($request, $organization);

        if ($invalid) {
            return $invalid;
        }

        return Inertia::render('Email/Unsubscribe', [
            'state' => EmailUnsubscribe::isUnsubscribed((int) $org->id, $email) ? 'done' : 'confirm',
            'organizationName' => $org->name,
            'email' => $email,
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, string $organization)
    {
        [$org, $email, $invalid] = $this->resolve($request, $organization);

        if ($invalid) {
            return $invalid;
        }

        EmailUnsubscribe::record((int) $org->id, $email);

        // نقرة واحدة من مزوّد البريد: يكفيه 200.
        if (!$request->header('X-Inertia') && $request->input('List-Unsubscribe') === 'One-Click') {
            return response('', 200);
        }

        return Inertia::render('Email/Unsubscribe', [
            'state' => 'done',
            'organizationName' => $org->name,
            'email' => $email,
        ]);
    }

    /** @return array{0: ?Organization, 1: string, 2: mixed} */
    private function resolve(Request $request, string $organizationUuid): array
    {
        if ($lang = $request->query('lang')) {
            app()->setLocale(substr((string) $lang, 0, 5));
        }

        $key = 'email-unsubscribe-invalid:' . $request->ip();

        if (!$request->hasValidSignature()) {
            if (RateLimiter::tooManyAttempts($key, self::INVALID_ATTEMPTS_PER_MINUTE)) {
                return [null, '', response(__('Too many requests.'), 429)];
            }

            RateLimiter::hit($key, 60);

            return [null, '', Inertia::render('Email/Unsubscribe', ['state' => 'invalid'])->toResponse($request)->setStatusCode(403)];
        }

        $organization = Organization::where('uuid', $organizationUuid)->first();
        $email = EmailUnsubscribe::normalize($request->query('email'));

        if (!$organization || $email === '') {
            return [null, '', Inertia::render('Email/Unsubscribe', ['state' => 'invalid'])->toResponse($request)->setStatusCode(404)];
        }

        return [$organization, $email, null];
    }
}
