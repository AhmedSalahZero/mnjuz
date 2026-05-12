<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\OrganizationContextService;
use Illuminate\Auth\Events\Logout;

/**
 * On logout, null out the platform-specific organization id and (for
 * web logouts) clear the legacy `current_organization` session key.
 *
 * Important: this fires only for guard-based logouts (web). Sanctum
 * token revocation does NOT fire Auth Logout events; the legacy
 * AuthController::logout() handles the API path by directly nulling
 * `current_mobile_organization_id`.
 *
 * Logging out of one platform must NOT touch the other platform's
 * column — that's why detectPlatform() is consulted.
 */
class ClearCurrentOrganizationOnLogout
{
    private OrganizationContextService $context;

    public function __construct(OrganizationContextService $context)
    {
        $this->context = $context;
    }

    public function handle(Logout $event): void
    {
        $user = $event->user;

        if (!$user instanceof User) {
            return;
        }

        if (session()->pull('skip_clear_organization_on_logout', false)) {
            return;
        }

        $this->context->clear($user);
    }
}
