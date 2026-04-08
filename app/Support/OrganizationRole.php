<?php

namespace App\Support;

/**
 * Organization-scoped team roles (teams.role): owner, manager, agent.
 */
final class OrganizationRole
{
    public const OWNER = 'owner';

    public const MANAGER = 'manager';

    public const AGENT = 'agent';

    /**
     * Roles that have full organization capabilities (billing, team management, etc.).
     */
    public static function privilegedRoles(): array
    {
        return [self::OWNER, self::MANAGER];
    }

    public static function isPrivileged(?string $role): bool
    {
        return $role !== null && in_array($role, self::privilegedRoles(), true);
    }

    public static function isAgent(?string $role): bool
    {
        return $role === self::AGENT;
    }

    /**
     * Legacy checks used `owner` only; manager is now equivalent for feature access.
     */
    public static function isOwnerOnly(?string $role): bool
    {
        return $role === self::OWNER;
    }
}
