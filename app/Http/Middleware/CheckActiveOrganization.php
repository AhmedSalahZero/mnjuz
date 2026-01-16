<?php

namespace App\Http\Middleware;

use Closure;

class CheckActiveOrganization
{
   public function handle($request, Closure $next)
    {
		
        if (auth()->check() && auth()->user()->canNotAccessDashboard()) {
			Auth()->logout();
            return redirect()->route('login');
        }
        return $next($request);
    }
}
