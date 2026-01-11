<?php

namespace App\Http\Middleware;

use Closure;


class CheckOrganizationId
{
    public function handle($request, Closure $next)
    {
        if (!session()->has('current_organization')) {
            return redirect()->route('user.organization.index');
        }

        return $next($request);
    }
}
