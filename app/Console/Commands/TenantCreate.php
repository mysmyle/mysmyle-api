<?php

namespace App\Console\Commands;

use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;
use Throwable;

class TenantCreate extends Command
{
    protected $signature = 'tenant:create {name} {slug? : Derived from the name if omitted} {db_name? : Derived from the slug if omitted}';

    protected $description = 'Provision a new tenant: creates the landlord record and the physical tenant database';

    public function handle(TenantProvisioningService $service): int
    {
        $this->info("Provisioning tenant [{$this->argument('name')}]...");

        try {
            $tenant = $service->provision(
                $this->argument('name'),
                $this->argument('slug'),
                $this->argument('db_name'),
            );
        } catch (Throwable $e) {
            $this->error("Provisioning failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Tenant [{$tenant->slug}] provisioned successfully — database [{$tenant->db_name}] created.");

        return self::SUCCESS;
    }
}
