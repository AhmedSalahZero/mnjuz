<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Support\OrganizationRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits organization "agent" role to approved areas (conversations, contacts, campaigns, templates, support, devices).
 */
class RestrictOrganizationAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (! $user || $user->role !== 'user') {
            return $next($request);
        }

        $organizationId = session('current_organization');
        if (! $organizationId) {
            return $next($request);
        }

        $team = Team::where('organization_id', $organizationId)->where('user_id', $user->id)->first();
        if (! $team || ! OrganizationRole::isAgent($team->role)) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        if ($this->isAllowedForAgent($path)) {
            return $next($request);
        }

        if ($path === 'dashboard' || str_starts_with($path, 'dashboard/')) {
            return redirect('/chats');
        }

        if ($path === 'settings/m' || str_starts_with($path, 'settings/m/')) {
            return redirect('/settings/devices');
        }

        abort(403, __('You do not have permission to access this section.'));
    }

    private function isAllowedForAgent(string $path): bool
    {
        $allowedPrefixes = [
            'chats',
            'chat',
            'tickets',
            'notes',
            'automation/contact',
            'contacts',
            'contact-groups',
            'contact-categories',
            'campaigns',
            'resend-all-failed-campaigns',
            'templates',
            'support',
            'settings/devices',
            'settings/device',
        ];

        foreach ($allowedPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
