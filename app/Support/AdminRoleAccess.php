<?php

namespace App\Support;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;

/**
 * Admin panel (users.role) — restricted roles like Developer use role_permissions.
 */
final class AdminRoleAccess
{
    public const DEVELOPER_ROLE_NAME = 'Developer';

    /**
     * Roles that bypass module/action checks (full admin UI).
     */
    public static function unrestrictedRoleNames(): array
    {
        return ['admin', 'Staff'];
    }

    public static function isRestrictedAdmin(User $user): bool
    {
        return strcasecmp((string) $user->role, self::DEVELOPER_ROLE_NAME) === 0;
    }

    public static function hasPermission(User $user, string $module, string $action): bool
    {
        if (! self::isRestrictedAdmin($user)) {
            return true;
        }

        $role = Role::where('name', $user->role)->first();
        if (! $role) {
            return false;
        }

        return RolePermission::where('role_id', $role->id)
            ->where('module', $module)
            ->where('action', $action)
            ->exists();
    }

    /**
     * Whether this admin user may access the given request path (e.g. admin/users, admin/dashboard).
     */
    public static function canAccessAdminPath(User $user, string $path): bool
    {
        if (! self::isRestrictedAdmin($user)) {
            return true;
        }

        $path = trim($path, '/');

        if ($path === 'admin' || $path === 'admin/dashboard' || str_starts_with($path, 'admin/dashboard/')) {
            return true;
        }

        if (str_starts_with($path, 'admin/users')) {
            return self::hasPermission($user, 'contacts', 'view')
                || self::hasPermission($user, 'contacts', 'create');
        }

        if (str_starts_with($path, 'admin/developer-tools')) {
            return self::hasPermission($user, 'developer_tools', 'view');
        }

        return false;
    }
}
