<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\OrganizationContextService;
use Illuminate\Auth\Events\Login;

/**
 * On every successful login (any guard), ensure the user has a valid
 * platform-specific organization id:
 *   • web login → users.current_web_organization_id
 *   • api login → users.current_mobile_organization_id
 *
 * Platform is auto-detected from the current request via the service.
 *
 * This complements EnsureCurrentOrganization middleware: the listener
 * sets the value AT login time so the very first authenticated request
 * already has a correct context, while the middleware re-validates on
 * every subsequent request.
 */
class AssignCurrentOrganizationOnLogin
{
    private OrganizationContextService $context;

    public function __construct(OrganizationContextService $context)
    {
        $this->context = $context;
    }

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (!$user instanceof User) {
            return;
        }

        if (($user->role ?? null) !== 'user') {
            return;
        }

        $this->context->ensureValid($user);
    }
}
