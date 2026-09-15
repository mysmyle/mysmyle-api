<?php

namespace App\Console\Commands;

use App\Models\Landlord\Designation;
use App\Models\Landlord\Module;
use App\Models\Landlord\Permission;
use App\Models\Landlord\Tenant;
use App\Models\Tenant\Role;
use App\Models\Tenant\RoleHasPermission;
use App\Models\Tenant\RoleModuleAccess;
use App\Models\Tenant\User;
use App\Services\TenantConnectionResolver;
use App\Services\UserRoleService;
use Illuminate\Console\Command;

/**
 * Give one existing account Control Panel access, so a tenant has someone who
 * can administer it.
 *
 * This is the same bootstrap TenantProvisioningService::seedAdmin performs for a
 * brand-new tenant, extracted for the case where the accounts already exist —
 * after a legacy import, for instance, where every user lands with role_id NULL
 * by design. Without it nobody can reach the Control Panel, and therefore nobody
 * can grant access to anyone else.
 *
 * Grants go on the role, not the user: the role template is the auditable unit,
 * and UserRoleService::assignRole snapshot-copies it onto the user and clears
 * the cached permission set.
 */
class TenantBootstrapAdmin extends Command
{
    protected $signature = 'tenant:bootstrap-admin
                            {slug : Tenant slug, e.g. vdc}
                            {email : The account to promote}
                            {--department= : Department name or fragment to take the Admin role from}';

    protected $description = 'Grant Control Panel access to one account via its department Admin role';

    public function handle(TenantConnectionResolver $resolver, UserRoleService $roles): int
    {
        $tenant = Tenant::where('slug', $this->argument('slug'))->first();

        if (! $tenant || ! $resolver->resolveAndBind($tenant->id)) {
            $this->error("Tenant [{$this->argument('slug')}] not found or not active.");

            return self::FAILURE;
        }

        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No account [{$this->argument('email')}] in tenant [{$tenant->slug}].");

            return self::FAILURE;
        }

        $adminDesignation = Designation::where('name', 'like', '%Admin%')->first();

        if (! $adminDesignation) {
            $this->error('No Admin designation found in the landlord catalog.');

            return self::FAILURE;
        }

        $role = Role::whereHas('department', fn ($q) => $q->where('name', 'like', '%'.$this->option('department').'%'))
            ->where('designation_id', $adminDesignation->id)
            ->with('department')
            ->first();

        if (! $this->option('department') || ! $role) {
            $this->error('Pass --department matching one department, which must have an Admin role.');

            return self::FAILURE;
        }

        $moduleId = Module::controlPanelId();

        if (! $moduleId) {
            $this->error('Control Panel module is missing from the landlord catalog.');

            return self::FAILURE;
        }

        RoleModuleAccess::updateOrCreate(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            ['allowed' => true],
        );

        // Every CP section, or the admin lands in the Control Panel with nothing
        // they can open.
        $permissionIds = Permission::whereHas('station', fn ($q) => $q->where('module_id', $moduleId))->pluck('id');

        foreach ($permissionIds as $permissionId) {
            RoleHasPermission::firstOrCreate(
                ['role_id' => $role->id, 'permission_id' => $permissionId],
                ['created_at' => now()],
            );
        }

        $roles->assignRole($user, $role->id, $tenant->id);

        $this->info("Granted Control Panel to [{$user->email}] via role #{$role->id}"
            ." ({$role->department->name} / {$adminDesignation->name}) — {$permissionIds->count()} permissions.");
        $this->line('<comment>Note:</comment> anyone else given this role inherits the same access.');

        return self::SUCCESS;
    }
}
