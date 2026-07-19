<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\OrganizationContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every authenticated request and guarantees that the user has
 * a valid platform-specific organization id:
 *   • web requests  → users.current_web_organization_id
 *   • api requests  → users.current_mobile_organization_id
 *
 * If the column is missing or points at an organization the user no
 * longer belongs to (revoked, banned, or deleted), the value is
 * auto-healed via OrganizationContextService.
 *
 * Behaviour:
 *  - Unauthenticated request → no-op.
 *  - Admin role (no team membership concept) → no-op.
 *  - Authenticated user → ensure & sync.
 *
 * Designed to be SAFE on every request: the fast-path is a single
 * EXISTS query when the column is already valid.
 */
class EnsureCurrentOrganization
{
    private OrganizationContextService $context;

    public function __construct(OrganizationContextService $context)
    {
        $this->context = $context;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->authenticatedUser($request);

        if ($user instanceof User && $this->shouldEnforceFor($user)) {
            $platform = $this->context->detectPlatform($request);
            $this->context->ensureValid($user, $platform);
        }

        return $next($request);
    }

    /**
     * Pull the authenticated user from any guard. We support both
     * web (session: user/admin) and api (sanctum) without modifying
     * existing auth flows.
     */
    private function authenticatedUser(Request $request)
    {
        return $request->user('user')
            ?? $request->user('admin')
            ?? $request->user('sanctum')
            ?? $request->user();
    }

    /**
     * Skip enforcement for accounts that don't participate in the
     * multi-organization model (admins). They have no team memberships
     * and forcing a value would create false positives.
     */
    private function shouldEnforceFor(User $user): bool
    {
        return ($user->role ?? null) === 'user';
    }
}
