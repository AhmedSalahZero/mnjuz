<?php

namespace App\Http\Middleware;

use App\Support\AdminRoleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminDeveloperAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('admin');
        if (! $user) {
            return $next($request);
        }

        $path = $request->path();
        if (! AdminRoleAccess::canAccessAdminPath($user, $path)) {
            abort(403, __('You do not have permission to access this section.'));
        }

        return $next($request);
    }
}
