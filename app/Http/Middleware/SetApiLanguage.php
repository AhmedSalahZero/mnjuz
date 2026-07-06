<?php

namespace App\Http\Middleware;


use Closure;
use Illuminate\Support\Facades\App;


class SetApiLanguage
{
    public function handle($request, Closure $next)
    {
		
        $locale =
            $request->header('Accept-Language')
            ?? $request->query('lang')
            ?? config('app.locale');

        // Optional: allow only supported languages
        if (! in_array($locale, ['en', 'ar'])) {
            $locale = config('app.locale');
        }
        App::setLocale($locale);

        return $next($request);
    }
}
