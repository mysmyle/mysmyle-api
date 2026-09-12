<?php

namespace Tests\Support;

use App\Models\Landlord\Tenant;
use App\Services\TenantDatabaseManager;
use RuntimeException;

/**
 * Records what the provisioning service asks for instead of running MySQL DDL.
 * The "tenant" connection is left pointing at the shared test schema.
 */
class FakeTenantDatabaseManager extends TenantDatabaseManager
{
    /** @var list<string> */
    public array $databasesCreated = [];

    /** @var list<string> */
    public array $databasesDropped = [];

    /** @var list<int> */
    public array $usersCreated = [];

    /** @var list<int> */
    public array $usersDropped = [];

    public ?string $failOn = null;

    public function createDatabase(string $dbName): void
    {
        $this->maybeFail('createDatabase');
        $this->databasesCreated[] = $dbName;
    }

    public function dropDatabase(string $dbName): void
    {
        $this->databasesDropped[] = $dbName;
    }

    public function createScopedUser(Tenant $tenant): void
    {
        $this->maybeFail('createScopedUser');
        $this->usersCreated[] = $tenant->id;

        $tenant->forceFill([
            'db_username' => 'test_t'.$tenant->id,
            'db_password' => 'test-password',
        ])->save();
    }

    public function dropScopedUser(Tenant $tenant): void
    {
        $this->usersDropped[] = $tenant->id;
    }

    public function bindConnection(Tenant $tenant): void
    {
        // no-op: tests keep the "tenant" connection on the shared test schema
    }

    public function verifyConnection(Tenant $tenant): void
    {
        $this->maybeFail('verifyConnection');
    }

    private function maybeFail(string $step): void
    {
        if ($this->failOn === $step) {
            throw new RuntimeException("Simulated failure at {$step}.");
        }
    }
}
