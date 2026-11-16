<?php

namespace App\Http\Middleware;


use Closure;
use Illuminate\Support\Facades\App;


class SetApiLanguage
{
    private const SUPPORTED = ['en', 'ar'];

    public function handle($request, Closure $next)
    {
		
        // ?lang= أوّلاً: المتصفّح يرسل Accept-Language دائماً («en-US,en;q=0.9»)
        // فكان ?lang= لا يُقرأ أبداً، وأخطاء widget المحادثة المباشرة تعود
        // بالإنجليزية لزائرٍ يقرأ بالعربية. بلا ?lang= لا يتغيّر شيء.
        $query = $request->query('lang');
        $header = $request->header('Accept-Language');

        $locale = match (true) {
            in_array($query, self::SUPPORTED, true) => $query,
            in_array($header, self::SUPPORTED, true) => $header,
            default => config('app.locale'),
        };

        App::setLocale($locale);

        return $next($request);
    }
}
