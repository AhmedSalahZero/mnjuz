<?php

namespace App\Http\Middleware;

use Closure;

class CheckActiveOrganization
{
   public function handle($request, Closure $next)
    {
		
        if (auth()->check() && auth()->user()->canNotAccessDashboard()) {
			if($request->expectsJson() || $request->is('api/*')){
				auth()->user()->tokens()->delete();
				return response()->json([
					'success' => false,
					'message' => __('Your account is not associated with any active organization. Please contact support.')
				], 403);
			}
			Auth()->logout();
            return redirect()->route('login');
        }
        return $next($request);
    }
}
