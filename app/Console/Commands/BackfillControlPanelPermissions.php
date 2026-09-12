<?php

namespace App\Console\Commands;

use App\Models\Landlord\Module;
use App\Models\Landlord\Permission;
use App\Models\Landlord\Tenant;
use App\Models\Tenant\RoleHasPermission;
use App\Models\Tenant\RoleModuleAccess;
use App\Models\Tenant\User;
use App\Models\Tenant\UserHasPermission;
use App\Models\Tenant\UserModuleAccess;
use App\Services\TenantConnectionResolver;
use App\Support\TenantCache;
use Illuminate\Console\Command;

/**
 * The Control Panel routes are now gated by CP.* station permissions. Anyone
 * who currently has CP *module* access predates those permissions and would be
 * locked out. This grants every CP permission to those users, and adds them to
 * the template of any role that has CP module access, so a later resync keeps
 * them. Idempotent.
 */
class BackfillControlPanelPermissions extends Command
{
    protected $signature = 'cp:backfill-permissions {slug? : Limit to one tenant slug}';

    protected $description = 'Grant the new CP section permissions to users/roles that already have Control Panel access';

    public function handle(TenantConnectionResolver $resolver): int
    {
        $cpModuleId = Module::controlPanelId();

        if (! $cpModuleId) {
            $this->error('Control Panel module not found — run StationSeeder first.');

            return self::FAILURE;
        }

        $cpPermissionIds = Permission::whereHas('station', fn ($q) => $q->where('module_id', $cpModuleId))
            ->pluck('id');

        if ($cpPermissionIds->isEmpty()) {
            $this->error('No CP permissions found — run `php artisan db:seed --class=StationSeeder` first.');

            return self::FAILURE;
        }

        $tenants = Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->argument('slug'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        if ($tenants->isEmpty()) {
            $this->warn('No matching active tenants.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            if (! $resolver->resolveAndBind($tenant->id)) {
                $this->warn("[{$tenant->slug}] skipped — connection unavailable.");

                continue;
            }

            [$roleCount, $userCount] = $this->backfillTenant($tenant->id, $cpModuleId, $cpPermissionIds);

            $this->line("<info>[{$tenant->slug}]</info> {$roleCount} role template(s), {$userCount} user(s) updated");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} roles touched, users touched
     */
    protected function backfillTenant(int $tenantId, int $cpModuleId, $cpPermissionIds): array
    {
        $roleIds = RoleModuleAccess::where('module_id', $cpModuleId)->where('allowed', true)->pluck('role_id');
        $userIds = UserModuleAccess::where('module_id', $cpModuleId)->where('allowed', true)->pluck('user_id');

        foreach ($roleIds as $roleId) {
            $this->grantMissing(RoleHasPermission::class, 'role_id', $roleId, $cpPermissionIds);
        }

        foreach ($userIds as $userId) {
            $this->grantMissing(UserHasPermission::class, 'user_id', $userId, $cpPermissionIds);
            TenantCache::forgetUserPermissions($tenantId, $userId);
        }

        // Users holding a backfilled role but no explicit CP module row still
        // need the permission rows (their snapshot was taken before CP.* existed).
        $roleHolderIds = User::whereIn('role_id', $roleIds)->pluck('id');

        foreach ($roleHolderIds as $userId) {
            if ($userIds->contains($userId)) {
                continue;
            }
            $this->grantMissing(UserHasPermission::class, 'user_id', $userId, $cpPermissionIds);
            TenantCache::forgetUserPermissions($tenantId, $userId);
        }

        return [$roleIds->count(), $userIds->merge($roleHolderIds)->unique()->count()];
    }

    /**
     * Insert the (owner, permission) rows that don't exist yet.
     */
    protected function grantMissing(string $model, string $ownerKey, int $ownerId, $permissionIds): void
    {
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
