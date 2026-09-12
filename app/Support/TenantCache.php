<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The cache store is shared across all tenants, but tenant-database ids are not
 * globally unique (tenant A and tenant B both have a user #1). Every cache key
 * that is derived from tenant-side data must be namespaced by the tenant id, or
 * one clinic will read another clinic's cached permissions.
 */
class TenantCache
{
    /** How long a computed permission set is trusted before it is rebuilt. */
    public const PERMISSIONS_TTL_HOURS = 6;

    public static function key(int $tenantId, string $suffix): string
    {
        return "t{$tenantId}:{$suffix}";
    }

    public static function userPermissionsKey(int $tenantId, int $userId): string
    {
        return static::key($tenantId, "user_permissions:{$userId}");
    }

    public static function forgetUserPermissions(?int $tenantId, int $userId): void
    {
        if ($tenantId === null) {
            return;
        }

        Cache::forget(static::userPermissionsKey($tenantId, $userId));
    }

    /** Resolve the current request's tenant id (set by the `tenant` middleware). */
    public static function currentTenantId(): ?int
    {
        $id = session('tenant_id');

        return $id ? (int) $id : null;
    }
}
