<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

class Localization
{
    /**
    * Handle an incoming request.
    *
    * @param  \Illuminate\Http\Request  $request
    * @param  \Closure  $next
    * @return mixed
    */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        // Priority: User language > Session locale > Default language
        if ($user && $user->language) {
            $locale = strtolower($user->language);
            App::setLocale($locale);
            // Set session locale to persist across requests
            Session::put('locale', $locale);
        } elseif (Session::has('locale')) {
            App::setLocale(strtolower(Session::get('locale')));
        } else {
            if ($this->isInstalled()) {
                $locale = Setting::where('key', 'default_language')->value('value') ?? 'en';
            } else {
                $locale = 'en';
            }
            
            App::setLocale(strtolower($locale));
        }

        return $next($request);
    }

    public function isInstalled(): bool
    {
        return file_exists(storage_path('installed'));
    }
}