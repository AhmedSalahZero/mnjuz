<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Support\OrganizationRole;
use Closure;
use Illuminate\Support\Facades\Auth;

class CheckClientRole
{
    public function handle($request, Closure $next)
    {
        // Check if the user is logged in
        if (Auth::check()) {
            $user = Auth::user();

            // Check if the user role is 'user'
            if ($user->role === 'user') {
                $organizationId = session()->get('current_organization');
                $team = Team::where('organization_id', $organizationId)->where('user_id', $user->id)->first();
                if ($team && OrganizationRole::isAgent($team->role)) {
                    return redirect('/chats');
                }
            }
        }

        // Subscription is active or user role is not 'user', proceed to the next page
        return $next($request);
    }
}
