<?php

namespace App\Console\Commands;

use App\Models\Landlord\Tenant;
use App\Services\TenantConnectionResolver;
use Illuminate\Console\Command;
use Throwable;

/**
 * Seed reference data into tenant databases.
 *
 * The counterpart to tenant:migrate. Tenant seeders are written against the
 * default connection, exactly as the tenant migrations are, so this binds the
 * tenant connection and hands `db:seed` the `--database=tenant` option — which
 * is what makes DB::table() inside a seeder write to the right clinic.
 *
 * Reference data only. Nothing here creates a user, a role or a permission;
 * those come from provisioning and the Control Panel.
 */
class TenantSeed extends Command
{
    protected $signature = 'tenant:seed
                            {slug? : Limit to one tenant slug}
                            {--class=* : Limit to specific seeder classes}';

    protected $description = 'Seed reference data into every active tenant database';

    /** Reference-data seeders, in dependency order. */
    private const SEEDERS = [
        \Database\Seeders\CountrySeeder::class,
        \Database\Seeders\PatientLookupSeeder::class,
        \Database\Seeders\AppointmentModuleSeeder::class,
    ];

    public function handle(TenantConnectionResolver $resolver): int
    {
        $seeders = $this->option('class') ?: self::SEEDERS;

        foreach ($seeders as $seeder) {
            if (! class_exists($seeder)) {
                $this->error("Seeder [{$seeder}] does not exist.");

                return self::FAILURE;
            }
        }

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

            foreach ($seeders as $seeder) {
                try {
                    $this->call('db:seed', [
                        '--class' => $seeder,
                        '--database' => 'tenant',
                        '--force' => true,
                    ]);
                } catch (Throwable $e) {
                    $this->error("  {$seeder} failed: {$e->getMessage()}");

                    return self::FAILURE;
                }
            }
        }

        return self::SUCCESS;
    }
}
