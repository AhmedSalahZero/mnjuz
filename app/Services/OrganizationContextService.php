<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Centralised authority for the user's current organization context,
 * tracked SEPARATELY per platform (web vs mobile).
 *
 * Schema:
 *   users.current_web_organization_id     ← web dashboard
 *   users.current_mobile_organization_id  ← mobile app
 *
 * Why two columns: a user can be looking at organization A on the
 * web while their mobile app is locked to organization B. Switching
 * one platform must NOT affect the other.
 *
 * Responsibilities:
 *  - Validate that an organization is one the authenticated user
 *    actually belongs to (security boundary).
 *  - Resolve the "best" organization for a user/platform.
 *  - Persist the platform-specific column and keep the legacy web
 *    session key `current_organization` in sync.
 */
class OrganizationContextService
{
    public const PLATFORM_WEB = 'web';
    public const PLATFORM_MOBILE = 'mobile';

    /** Web session key used by legacy code paths. */
    public const SESSION_KEY = 'current_organization';

    private const COLUMN_BY_PLATFORM = [
        self::PLATFORM_WEB => 'current_web_organization_id',
        self::PLATFORM_MOBILE => 'current_mobile_organization_id',
    ];

    /**
     * Decide which platform a request belongs to. JSON / api/* requests
     * are mobile; everything else is web.
     */
    public function detectPlatform(?Request $request = null): string
    {
        $request = $request ?? (function () {
            try {
                return app('request');
            } catch (\Throwable $e) {
                return null;
            }
        })();

        if (!$request instanceof Request) {
            return self::PLATFORM_WEB;
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return self::PLATFORM_MOBILE;
        }

        return self::PLATFORM_WEB;
    }

    /**
     * Read the active organization for a user on a given platform.
     */
    public function getCurrent(User $user, string $platform): ?int
    {
        $column = $this->columnFor($platform);
        $value = $user->{$column} ?? null;
        return $value ? (int) $value : null;
    }

    /**
     * Remove the user's membership from an organization (soft-deletes the team row).
     * The organization owner cannot leave; they must transfer ownership first.
     *
     * @return array{ok: bool, message?: string, status?: int}
     */
    public function detachMembership(User $user, int $organizationId): array
    {
        if ($organizationId <= 0) {
            return [
                'ok' => false,
                'message' => __('Invalid organization.'),
                'status' => 422,
            ];
        }

        $team = Team::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$team) {
            return [
                'ok' => false,
                'message' => __('You are not a member of this organization.'),
                'status' => 404,
            ];
        }

        if ($team->role === 'owner') {
            return [
                'ok' => false,
                'message' => __('You cannot leave as the organization owner. Transfer ownership first.'),
                'status' => 403,
            ];
        }

        $team->delete();

        return ['ok' => true];
    }

    /**
     * True if the given organization id is one the user belongs to AND
     * the organization itself is active (not soft-deleted, not banned).
     *
     * Single SELECT query. Returns false on null/0.
     */
    public function userBelongsToOrganization(User $user, $organizationId): bool
    {
        $organizationId = (int) $organizationId;
        if ($organizationId <= 0) {
            return false;
        }

        return Team::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->whereHas('organization', function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('is_banned')->orWhere('is_banned', false);
                });
                // SoftDeletes on Organization auto-filters trashed rows.
            })
            ->exists();
    }

    /**
     * Resolve the best organization id for the user on the given platform:
     *   1. the platform's column  (if still valid — acts as "last used"
     *      whenever the column has not been cleared)
     *   2. the first organization the user belongs to
     *   3. null  (user has no organizations)
     *
     * Pure read — does NOT persist anything.
     */
    public function resolveFor(User $user, string $platform): ?int
    {
        $current = $this->getCurrent($user, $platform);
        if ($current && $this->userBelongsToOrganization($user, $current)) {
            return $current;
        }

        return $this->firstAvailableOrganizationId($user);
    }

    /**
     * Persist a chosen organization id as the user's current context for
     * the given platform. Returns true on success, false if the user does
     * not belong to it.
     *
     * Web platform also syncs the `current_organization` session key for
     * legacy code that still reads from the session.
     */
    public function setCurrent(User $user, $organizationId, ?string $platform = null): bool
    {
        $organizationId = (int) $organizationId;
        if ($organizationId <= 0) {
            return false;
        }

        if (!$this->userBelongsToOrganization($user, $organizationId)) {
            return false;
        }

        $platform = $platform ?? $this->detectPlatform();
        $column = $this->columnFor($platform);

        if ((int) ($user->{$column} ?? 0) !== $organizationId) {
            $user->{$column} = $organizationId;
            $user->save();
        }

        if ($platform === self::PLATFORM_WEB) {
            $this->syncSession($organizationId);
        }

        return true;
    }

    /**
     * Apply current = null for the given platform (used on logout).
     */
    public function clear(User $user, ?string $platform = null): void
    {
        $platform = $platform ?? $this->detectPlatform();
        $column = $this->columnFor($platform);

        if ($user->{$column} !== null) {
            $user->{$column} = null;
            $user->save();
        }

        if ($platform === self::PLATFORM_WEB) {
            $this->forgetSession();
        }
    }

    /**
     * Middleware entry point: ensure the platform-specific column is
     * valid. Auto-heals invalid/null values using {@see resolveFor()}.
     *
     * Returns the final organization id (or null if the user has no
     * organizations at all). Idempotent: a no-op when state is already
     * valid, so it is safe to invoke on every request.
     */
    public function ensureValid(User $user, ?string $platform = null): ?int
    {
        $platform = $platform ?? $this->detectPlatform();
        $column = $this->columnFor($platform);
        $current = (int) ($user->{$column} ?? 0);

        if ($current > 0 && $this->userBelongsToOrganization($user, $current)) {
            // Fast path: state already valid. Just keep the session in
            // sync for the web platform.
            if ($platform === self::PLATFORM_WEB) {
                $this->syncSession($current);
            }
            return $current;
        }

        // Web + mobile: more than one workspace and no valid current id — do not
        // auto-pick a default. Web user must use /select-organization; API clients
        // must call set-current-organization (same as login JSON when org count > 1).
        if (
            ($platform === self::PLATFORM_WEB || $platform === self::PLATFORM_MOBILE)
            && $this->activeOrganizationMembershipCount($user) > 1
        ) {
            if ($user->{$column} !== null) {
                $user->{$column} = null;
                $user->save();
            }
            if ($platform === self::PLATFORM_WEB) {
                $this->forgetSession();
            }

            return null;
        }

        $resolved = $this->resolveFor($user, $platform);

        if ($resolved === null) {
            if ($user->{$column} !== null) {
                $user->{$column} = null;
                $user->save();
            }
            if ($platform === self::PLATFORM_WEB) {
                $this->forgetSession();
            }
            return null;
        }

        if ((int) ($user->{$column} ?? 0) !== $resolved) {
            $user->{$column} = $resolved;
            $user->save();
        }

        if ($platform === self::PLATFORM_WEB) {
            $this->syncSession($resolved);
        }

        return $resolved;
    }

    private function columnFor(string $platform): string
    {
        if (!isset(self::COLUMN_BY_PLATFORM[$platform])) {
            throw new \InvalidArgumentException("Unknown platform: {$platform}");
        }
        return self::COLUMN_BY_PLATFORM[$platform];
    }

    private function firstAvailableOrganizationId(User $user): ?int
    {
        $row = Team::query()
            ->where('user_id', $user->id)
            ->whereHas('organization', function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('is_banned')->orWhere('is_banned', false);
                });
            })
            ->orderBy('id')
            ->value('organization_id');

        return $row ? (int) $row : null;
    }

    private function activeOrganizationMembershipCount(User $user): int
    {
        return Team::query()
            ->where('user_id', $user->id)
            ->whereHas('organization', function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('is_banned')->orWhere('is_banned', false);
                });
            })
            ->count();
    }

    private function syncSession(int $organizationId): void
    {
        if (!$this->sessionAvailable()) {
            return;
        }

        session()->put(self::SESSION_KEY, $organizationId);
    }

    private function forgetSession(): void
    {
        if (!$this->sessionAvailable()) {
            return;
        }

        session()->forget(self::SESSION_KEY);
    }

    /**
     * True only when the HTTP session store is bound and started.
     *
     * Important: {@see app('session')} is the SessionManager, which does not
     * implement {@see \Illuminate\Session\Store::isStarted()}. Using it here
     * always returned false, so `current_organization` was never written to
     * the session while `current_web_organization_id` was updated in the DB
     * — and {@see \App\Http\Middleware\CheckOrganizationId} redirected users
     * back to the organization picker forever.
     */
    private function sessionAvailable(): bool
    {
        try {
            if (! app()->bound('session')) {
                return false;
            }

            $store = session()->driver();

            return $store instanceof \Illuminate\Contracts\Session\Session
                && $store->isStarted();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
