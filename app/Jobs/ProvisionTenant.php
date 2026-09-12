<?php

namespace App\Jobs;

use App\Services\TenantProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProvisionTenant implements ShouldQueue
{
    use Queueable;

    /** Provisioning leaves partial state on failure — never auto-retry. */
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public int $tenantId,
        public string $adminName,
        public string $adminEmail,
    ) {}

    public function handle(TenantProvisioningService $service): void
    {
        $service->runProvisioning(
            $this->tenantId,
            $this->adminName,
            $this->adminEmail,
        );
    }

    /**
     * Safety net: runProvisioning() records failure itself, but if it threw
     * before it could (or the worker died), make sure the tenant is marked.
     */
    public function failed(?Throwable $e): void
    {
        app(TenantProvisioningService::class)->markProvisioningFailed(
            $this->tenantId,
            $e?->getMessage() ?? 'Provisioning worker failed.',
        );
    }
}
