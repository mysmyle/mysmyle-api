<?php

namespace App\Console\Commands;

use App\Models\Landlord\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;
use Throwable;

class TenantProvisionDbUser extends Command
{
    protected $signature = 'tenant:provision-db-user {slug? : Limit to one tenant slug} {--force : Re-mint even if a dedicated user is already set}';

    protected $description = 'Give existing tenants their own scoped MySQL user (migrate off shared root credentials)';

    public function handle(TenantProvisioningService $service): int
    {
        $prefix = config('tenancy.db.user_prefix');

        $tenants = Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->argument('slug'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        if ($tenants->isEmpty()) {
            $this->warn('No matching active tenants.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($tenants as $tenant) {
            $alreadyDedicated = str_starts_with((string) $tenant->db_username, $prefix);

            if ($alreadyDedicated && ! $this->option('force')) {
                $this->line("[{$tenant->slug}] already on a dedicated user ({$tenant->db_username}) — skipped.");

                continue;
            }

            try {
                $service->reprovisionDbUser($tenant);
                $this->info("[{$tenant->slug}] now using {$tenant->fresh()->db_username} (scoped to `{$tenant->db_name}`).");
            } catch (Throwable $e) {
                $failed++;
                $this->error("[{$tenant->slug}] failed: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
