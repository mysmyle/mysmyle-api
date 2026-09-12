<?php

namespace App\Console\Commands;

use App\Models\Landlord\Designation;
use App\Models\Landlord\Module;
use App\Models\Landlord\Tenant;
use App\Models\Tenant\Role;
use App\Models\Tenant\RoleModuleAccess;
use App\Models\Tenant\User;
use App\Models\Tenant\UserModuleAccess;
use App\Services\TenantConnectionResolver;
use Illuminate\Console\Command;

class TenantBackfillControlPanel extends Command
{
    protected $signature = 'tenant:backfill-control-panel {slug? : Limit to one tenant slug} {--designation=Admin : Designation name fragment to grant}';

    protected $description = 'Grant Control Panel access to existing tenants\' admin roles and their users (non-destructive)';

    public function handle(TenantConnectionResolver $resolver): int
    {
        $moduleId = Module::controlPanelId();

        if (! $moduleId) {
            $this->error('Control Panel module not found. Run: php artisan db:seed --class=ModuleSeeder');

            return self::FAILURE;
        }

        $designationIds = Designation::where('name', 'like', '%'.$this->option('designation').'%')->pluck('id');

        if ($designationIds->isEmpty()) {
            $this->error("No designation matching [{$this->option('designation')}].");

            return self::FAILURE;
        }

        $tenants = Tenant::query()
            ->where('status', 'active')
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

            // Only unlock admin roles that actually have a user on them — avoids
            // pre-authorising empty role templates across every department.
            $roleIds = Role::whereIn('designation_id', $designationIds)
                ->whereIn('id', User::whereNotNull('role_id')->distinct()->pluck('role_id'))
                ->pluck('id');

            if ($roleIds->isEmpty()) {
                $this->line("[{$tenant->slug}] no populated admin roles.");

                continue;
            }

            foreach ($roleIds as $roleId) {
                RoleModuleAccess::updateOrCreate(
                    ['role_id' => $roleId, 'module_id' => $moduleId],
                    ['allowed' => true]
                );
            }

            $userIds = User::whereIn('role_id', $roleIds)->pluck('id');

            foreach ($userIds as $userId) {
                UserModuleAccess::updateOrCreate(
                    ['user_id' => $userId, 'module_id' => $moduleId],
                    ['allowed' => true]
                );
            }

            $this->info("[{$tenant->slug}] granted CP to {$roleIds->count()} role(s), {$userIds->count()} user(s).");
        }

        return self::SUCCESS;
    }
}
