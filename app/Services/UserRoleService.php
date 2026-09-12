<?php

namespace App\Services;

use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Models\Tenant\UserHasPermission;
use App\Models\Tenant\UserModuleAccess;
use App\Support\TenantCache;
use Illuminate\Support\Facades\DB;

class UserRoleService
{
    /**
     * Assign a role and snapshot-copy its permission + module-access template
     * onto the user. Per-user tweaks made afterwards are independent of the role.
     */
    public function assignRole(User $user, ?int $roleId, ?int $tenantId): void
    {
        DB::connection('tenant')->transaction(function () use ($user, $roleId) {
            $user->update(['role_id' => $roleId]);

            if ($roleId) {
                $this->copyRoleTemplateToUser($user, $roleId);
            }
        });

        // The user's permission rows just changed — drop the cached set.
        TenantCache::forgetUserPermissions($tenantId, $user->id);
    }

    /**
     * Re-apply a role's current template to every user holding that role,
     * overwriting any per-user customisation. This is the explicit "push role
     * changes to current holders" action — it is never automatic.
     */
    public function resyncRoleUsers(int $roleId, ?int $tenantId): int
    {
        $users = User::where('role_id', $roleId)->get();

        DB::connection('tenant')->transaction(function () use ($users, $roleId) {
            foreach ($users as $user) {
                $this->copyRoleTemplateToUser($user, $roleId);
            }
        });

        foreach ($users as $user) {
            TenantCache::forgetUserPermissions($tenantId, $user->id);
        }

        return $users->count();
    }

    protected function copyRoleTemplateToUser(User $user, int $roleId): void
    {
        $role = Role::findOrFail($roleId);

        UserHasPermission::where('user_id', $user->id)->delete();
        UserModuleAccess::where('user_id', $user->id)->delete();

        $rolePermissionIds = $role->permissions()->pluck('permission_id');
        $permissionRows = $rolePermissionIds->map(fn ($id) => [
            'user_id' => $user->id,
            'permission_id' => $id,
            'created_at' => now(),
        ])->all();

        if (! empty($permissionRows)) {
            UserHasPermission::insert($permissionRows);
        }

        $roleModuleAccess = $role->moduleAccess()->get(['module_id', 'allowed']);
        $moduleAccessRows = $roleModuleAccess->map(fn ($entry) => [
            'user_id' => $user->id,
            'module_id' => $entry->module_id,
            'allowed' => $entry->allowed,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if (! empty($moduleAccessRows)) {
            UserModuleAccess::insert($moduleAccessRows);
        }
    }
}
