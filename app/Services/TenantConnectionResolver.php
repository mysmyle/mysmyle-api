<?php

namespace App\Services;

use App\Models\Landlord\Tenant;

class TenantConnectionResolver
{
    public function __construct(protected TenantDatabaseManager $databases) {}

    /**
     * Load an active tenant and point the "tenant" connection at its database.
     *
     * The tenant row is read fresh every call: its db_username / db_password are
     * decrypted in-process and never written to the cache.
     */
    public function resolveAndBind(int $tenantId): ?Tenant
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant || $tenant->status !== Tenant::STATUS_ACTIVE) {
            return null;
        }

        $this->databases->bindConnection($tenant);

        return $tenant;
    }

    /**
     * Bind the "tenant" connection without a status check — used during
     * provisioning, before the tenant is active.
     */
    public function bind(Tenant $tenant): void
    {
        $this->databases->bindConnection($tenant);
    }
}
