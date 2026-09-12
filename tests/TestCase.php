<?php

namespace Tests;

use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\Tenant;
use App\Models\Tenant\User;
use App\Services\TenantDatabaseManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeTenantDatabaseManager;

abstract class TestCase extends BaseTestCase
{
    private static bool $migrated = false;

    protected FakeTenantDatabaseManager $tenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();

        // Landlord + tenant share one physical test schema; the DB manager
        // (which would create real per-tenant databases) is faked.
        config([
            'database.connections.tenant.driver' => 'mysql',
            'database.connections.tenant.host' => config('database.connections.landlord.host'),
            'database.connections.tenant.port' => config('database.connections.landlord.port'),
            'database.connections.tenant.database' => config('database.connections.landlord.database'),
            'database.connections.tenant.username' => config('database.connections.landlord.username'),
            'database.connections.tenant.password' => config('database.connections.landlord.password'),
        ]);
        DB::purge('tenant');

        $this->migrateFreshOnce();
        $this->truncateTables();

        // Tenant db-ids are reused across tests; the permission cache is keyed by
        // them, so a stale entry would leak permissions between tests.
        Cache::flush();

        $this->tenantDatabases = new FakeTenantDatabaseManager;
        $this->app->instance(TenantDatabaseManager::class, $this->tenantDatabases);
    }

    private function migrateFreshOnce(): void
    {
        if (self::$migrated) {
            return;
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        self::$migrated = true;
    }

    private function truncateTables(): void
    {
        $connection = DB::connection('landlord');
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');

        // SHOW TABLES is scoped to the current database (unlike getTableListing()).
        foreach ($connection->select('SHOW TABLES') as $row) {
            $name = array_values((array) $row)[0];

            if ($name === 'migrations') {
                continue;
            }

            $connection->table($name)->truncate();
        }

        $connection->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function actingAsTenantUser(User $user, Tenant $tenant): static
    {
        return $this->withHeader('Origin', 'http://localhost')
            ->withSession(['tenant_id' => $tenant->id, 'auth_user_id' => $user->id]);
    }

    protected function actingAsLandlordAdmin(LandlordAdmin $admin): static
    {
        return $this->withHeader('Origin', 'http://localhost')
            ->withSession(['landlord_admin_id' => $admin->id]);
    }
}
