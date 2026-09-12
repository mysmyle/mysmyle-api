<?php

namespace App\Services;

use App\Models\Landlord\Module;
use App\Models\Landlord\Permission;
use App\Models\Tenant\Role;
use App\Models\Tenant\RoleHasPermission;
use App\Models\Tenant\User;
use App\Models\Tenant\UserHasPermission;
use App\Support\TenantCache;
use Illuminate\Support\Collection;

/**
 * A *system* module (Control Panel, …) has no meaningful "partial" default — its
 * stations ARE the module. So allowing/denying a system module also grants/
 * revokes all of that module's station permissions on the same role or user.
 *
 * Clinical modules are left alone: their stations are picked individually in the
 * permission matrix.
 */
class ModuleAccessService
{
    public function syncRole(Role $role, int $moduleId, bool $allowed): void
    {
        $permissionIds = $this->systemModulePermissionIds($moduleId);

        if ($permissionIds === null) {
            return;
        }

        $this->apply(RoleHasPermission::class, 'role_id', $role->id, $permissionIds, $allowed);
    }

    public function syncUser(User $user, int $moduleId, bool $allowed, ?int $tenantId): void
    {
        $permissionIds = $this->systemModulePermissionIds($moduleId);

        if ($permissionIds === null) {
            return;
        }

        $this->apply(UserHasPermission::class, 'user_id', $user->id, $permissionIds, $allowed);

        // the user's effective permissions just changed
        TenantCache::forgetUserPermissions($tenantId, $user->id);
    }

    /**
     * The landlord permission ids for a system module, or null if the module is
     * not a system module (nothing to sync).
     *
     * @return Collection<int, int>|null
     */
    private function systemModulePermissionIds(int $moduleId)
    {
        $module = Module::find($moduleId);

        if (! $module || $module->kind !== Module::KIND_SYSTEM) {
            return null;
        }

        return Permission::whereHas('station', fn ($q) => $q->where('module_id', $moduleId))->pluck('id');
    }

    private function apply(string $model, string $ownerKey, int $ownerId, $permissionIds, bool $allowed): void
    {
        if (! $allowed) {
            $model::where($ownerKey, $ownerId)->whereIn('permission_id', $permissionIds)->delete();

            return;
        }

        $existing = $model::where($ownerKey, $ownerId)
            ->whereIn('permission_id', $permissionIds)
            ->pluck('permission_id');

        $rows = $permissionIds->diff($existing)->map(fn ($id) => [
            $ownerKey => $ownerId,
            'permission_id' => $id,
            'created_at' => now(),
        ])->all();

        if (! empty($rows)) {
            $model::insert($rows);
        }
    }
}
