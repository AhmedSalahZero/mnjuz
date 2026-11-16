<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'webhook/*',
        // إلغاء الاشتراك بنقرة واحدة (RFC 8058): مزوّد البريد يرسل POST من
        // خادمه بلا جلسة. التوقيع في الرابط هو التصريح.
        'email/unsubscribe/*',
    ];
}
