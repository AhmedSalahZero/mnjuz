<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Support\Facades\Auth;

class CheckEmailVerification
{
    public function handle($request, Closure $next)
    {
        // Check if the user is logged in
		$start = microtime(true);
        if (Auth::check()) {
            $user = Auth::user();
            $config = Setting::where('key', 'verify_email')->first();
            // Check if the user role is 'user'
            if (isset($config->value) && $config->value == '1' && $user->email_verified_at === NULL) {
				$end = microtime(true) - $start;
                return to_route('verification.notice');
            }
        }
			$end = microtime(true) - $start;
        // Subscription is active or user role is not 'user', proceed to the next page
        return $next($request);
    }
}
