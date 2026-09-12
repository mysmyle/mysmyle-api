<?php

namespace App\Services;

use App\Models\Landlord\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The physical side of tenancy: creating/dropping a tenant's MySQL database and
 * its scoped user, and pointing the "tenant" connection at it. Kept separate
 * from TenantProvisioningService (which orchestrates) so the DDL can be faked
 * in tests.
 */
class TenantDatabaseManager
{
    public function createDatabase(string $dbName): void
    {
        $this->admin()->statement(
            "CREATE DATABASE IF NOT EXISTS `{$this->safeName($dbName)}`
             CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    public function dropDatabase(string $dbName): void
    {
        DB::connection('tenant')->disconnect();
        $this->admin()->statement("DROP DATABASE IF EXISTS `{$this->safeName($dbName)}`");
    }

    /**
     * Mint a MySQL account whose privileges are scoped to this tenant's schema
     * only, and store its credentials on the tenant row (encrypted by the cast).
     * Idempotent: re-running rotates the password.
     */
    public function createScopedUser(Tenant $tenant): void
    {
        $user = $this->username($tenant);
        $host = config('tenancy.db.user_host');
        $password = Str::random(32); // [A-Za-z0-9] — safe to interpolate
        $db = $this->safeName($tenant->db_name);
        $grant = config('tenancy.db.grant');

        $this->admin()->statement("CREATE USER IF NOT EXISTS '{$user}'@'{$host}' IDENTIFIED BY '{$password}'");
        $this->admin()->statement("ALTER USER '{$user}'@'{$host}' IDENTIFIED BY '{$password}'");
        $this->admin()->statement("GRANT {$grant} ON `{$db}`.* TO '{$user}'@'{$host}'");
        $this->admin()->statement('FLUSH PRIVILEGES');

        $tenant->forceFill([
            'db_username' => $user,
            'db_password' => $password,
        ])->save();
    }

    public function dropScopedUser(Tenant $tenant): void
    {
        $user = $this->username($tenant);
        $host = config('tenancy.db.user_host');

        $this->admin()->statement("DROP USER IF EXISTS '{$user}'@'{$host}'");
        $this->admin()->statement('FLUSH PRIVILEGES');
    }

    /** Point the "tenant" connection at this tenant's database. */
    public function bindConnection(Tenant $tenant): void
    {
        config([
            'database.connections.tenant.host' => $tenant->db_host,
            'database.connections.tenant.port' => $tenant->db_port,
            'database.connections.tenant.database' => $tenant->db_name,
            'database.connections.tenant.username' => $tenant->db_username,
            'database.connections.tenant.password' => $tenant->db_password,
        ]);

        DB::purge('tenant');
        DB::reconnect('tenant');
    }

    public function verifyConnection(Tenant $tenant): void
    {
        $this->bindConnection($tenant);
        DB::connection('tenant')->select('select 1');
    }

    protected function admin(): Connection
    {
        return DB::connection(config('tenancy.db.admin_connection'));
    }

    protected function username(Tenant $tenant): string
    {
        return config('tenancy.db.user_prefix').$tenant->id;
    }

    protected function safeName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $name);
    }
}
