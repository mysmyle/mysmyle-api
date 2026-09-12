<?php

namespace App\Console\Commands;

use App\Models\Landlord\Tenant;
use App\Services\TenantConnectionResolver;
use Illuminate\Console\Command;

class TenantMigrate extends Command
{
    protected $signature = 'tenant:migrate {slug? : Limit to one tenant slug} {--pretend : Show the SQL without running it}';

    protected $description = 'Run the tenant migrations against every active tenant database';

    public function handle(TenantConnectionResolver $resolver): int
    {
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

            $this->line("<info>[{$tenant->slug}]</info> ({$tenant->db_name})");

            $this->call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
                '--pretend' => $this->option('pretend'),
            ]);
        }

        return self::SUCCESS;
    }
}
