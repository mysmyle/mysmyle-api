<?php

namespace App\Console\Commands;

use App\Console\Legacy\LegacyAppointmentImporter;
use App\Console\Legacy\LegacyAppointmentProcessImporter;
use App\Console\Legacy\LegacyAppointmentRescheduleImporter;
use App\Console\Legacy\LegacyAppointmentTentativeImporter;
use App\Console\Legacy\LegacyAppointmentTypeImporter;
use App\Console\Legacy\LegacyConfirmationRapImporter;
use App\Console\Legacy\LegacyDoctorImporter;
use App\Console\Legacy\LegacyPatientImporter;
use App\Console\Legacy\LegacyPayerImporter;
use App\Console\Legacy\LegacyStaffImporter;
use App\Console\Legacy\LegacyUserImporter;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantUser;
use App\Services\TenantConnectionResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\table;

/**
 * One-time import of identity data from the legacy VDC database.
 *
 * Deliberately writes nothing to the RBAC tables — no role_id, no
 * user_has_permissions, no user_module_access. Roles are assigned afterwards
 * through the control panel, which snapshot-copies the role template and
 * invalidates the permission cache. See docs/LEGACY_IMPORT.md.
 */
class ImportLegacyIdentity extends Command
{
    protected $signature = 'legacy:import
                            {--tenant=vdc : Tenant slug to import into}
                            {--only=* : Limit to specific importers: staff, users, doctors}
                            {--dry-run : Read and report without writing}
                            {--drop-seed-admins : First delete the seeded admin accounts occupying ids 1-3}';

    protected $description = 'Import reference and patient data from the legacy VDC database into a tenant';

    /**
     * Importer classes in dependency order.
     *   users         needs staff
     *   patients      needs payers (for the insurance block) and the
     *                 lookup/country reference data seeded by tenant:seed
     *   doctors       needs staff — every appointment points at one
     *   appointments  needs doctors, patients and appointment types, plus the
     *                 statuses seeded by AppointmentModuleSeeder
     *   the four child importers all need appointments
     */
    private const IMPORTERS = [
        'staff' => LegacyStaffImporter::class,
        'users' => LegacyUserImporter::class,
        'payers' => LegacyPayerImporter::class,
        'patients' => LegacyPatientImporter::class,
        'doctors' => LegacyDoctorImporter::class,
        'appointment-types' => LegacyAppointmentTypeImporter::class,
        'appointments' => LegacyAppointmentImporter::class,
        'appointment-processes' => LegacyAppointmentProcessImporter::class,
        'confirmations' => LegacyConfirmationRapImporter::class,
        'reschedules' => LegacyAppointmentRescheduleImporter::class,
        'tentatives' => LegacyAppointmentTentativeImporter::class,
    ];

    /**
     * Tables with no natural key, which are therefore reloaded rather than
     * upserted. `confirmation_raps` deliberately allows several contacts at the
     * same tier, so there is nothing to upsert on — see the importer.
     */
    private const RELOAD_TABLES = [
        'confirmations' => 'confirmation_raps',
    ];

    public function handle(TenantConnectionResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $tenant = Tenant::where('slug', $this->option('tenant'))->first();

        if (! $tenant) {
            $this->error("Tenant [{$this->option('tenant')}] not found.");

            return self::FAILURE;
        }

        if (! $resolver->resolveAndBind($tenant->id)) {
            $this->error("Tenant [{$tenant->slug}] is not active — cannot bind its database.");

            return self::FAILURE;
        }

        $this->line("Source: <info>".config('database.connections.legacy.database')."</info>"
            ." -> Tenant: <info>{$tenant->slug}</info> ({$tenant->db_name})");

        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be written.');
        }

        if ($this->option('drop-seed-admins') && ! $this->dropSeedAdmins($tenant, $dryRun)) {
            return self::FAILURE;
        }

        $only = $this->option('only') ?: array_keys(self::IMPORTERS);

        foreach ($only as $name) {
            if (! isset(self::IMPORTERS[$name])) {
                $this->error("Unknown importer [{$name}]. Available: ".implode(', ', array_keys(self::IMPORTERS)));

                return self::FAILURE;
            }
        }

        foreach ($only as $name) {
            $importer = new (self::IMPORTERS[$name])($dryRun, $tenant->id);
            $importer->setOutput($this->output);

            $this->newLine();
            $this->line("<comment>--</comment> {$importer->label()}");

            try {
                // One transaction per importer: a mid-run failure leaves nothing
                // half-written, and the next attempt starts from a clean table.
                $dryRun
                    ? $importer->run()
                    : DB::connection('tenant')->transaction(function () use ($importer, $name) {
                        // Reload-only tables are cleared inside the same
                        // transaction, so a failure cannot leave the table empty.
                        if ($table = self::RELOAD_TABLES[$name] ?? null) {
                            DB::connection('tenant')->table($table)->delete();
                        }

                        $importer->run();
                    });
            } catch (Throwable $e) {
                $this->error("  {$importer->label()} failed: {$e->getMessage()}");

                return self::FAILURE;
            }

            $stats = $importer->stats();
            $this->line("  read <info>{$stats['read']}</info>"
                ."  written <info>{$stats['written']}</info>"
                ."  notes <comment>{$stats['skipped']}</comment>");

            if ($rows = $importer->skippedRows()) {
                table(['Legacy id', 'Note'], $rows);
            }
        }

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete — no changes made.' : 'Import complete.');

        return self::SUCCESS;
    }

    /**
     * Remove the three seeded admin accounts so the legacy rows that own ids 1-3
     * can take them.
     *
     * Deliberately surgical rather than a migrate:fresh — the tenant's 12
     * departments and 48 roles are real curated data with no seeder behind them
     * (provisioning only ever creates an "Administration" department), so a wipe
     * would destroy work that cannot be regenerated.
     *
     * The dependent rows clean themselves up: user_has_permissions and
     * user_module_access are cascadeOnDelete, audit_logs.user_id is nullOnDelete.
     */
    private function dropSeedAdmins(Tenant $tenant, bool $dryRun): bool
    {
        $tenantDb = DB::connection('tenant');

        $seeds = $tenantDb->table('users')
            ->whereIn('id', [1, 2, 3])
            ->get(['id', 'email', 'staff_id']);

        if ($seeds->isEmpty()) {
            $this->line('  No seeded accounts on ids 1-3 — nothing to drop.');

            return true;
        }

        $this->newLine();
        $this->line('<comment>--</comment> seed admins to remove');
        table(
            ['User id', 'Email', 'Staff id'],
            $seeds->map(fn ($u) => [$u->id, $u->email, $u->staff_id ?? '—'])->all(),
        );

        $protected = $seeds->reject(fn ($u) => str_starts_with($u->email, 'admin'));

        if ($protected->isNotEmpty()) {
            $this->error('  Refusing to drop: id '.$protected->pluck('id')->join(', ')
                .' does not look like a seeded admin account. Inspect manually.');

            return false;
        }

        if ($dryRun) {
            $this->warn('  DRY RUN — would delete the rows above plus their landlord login rows.');

            return true;
        }

        if ($this->input->isInteractive() && ! confirm('Delete these seeded accounts?', default: false)) {
            $this->line('  Skipped.');

            return false;
        }

        $emails = $seeds->pluck('email')->all();
        $staffIds = $seeds->pluck('staff_id')->filter()->all();

        $tenantDb->transaction(function () use ($tenantDb, $seeds, $staffIds) {
            // Cascades clear user_has_permissions / user_module_access; audit_logs
            // keeps its history with user_id nulled.
            $tenantDb->table('users')->whereIn('id', $seeds->pluck('id'))->delete();

            if ($staffIds) {
                $tenantDb->table('staff')->whereIn('id', $staffIds)->delete();
            }
        });

        TenantUser::where('tenant_id', $tenant->id)->whereIn('email', $emails)->delete();

        $this->info('  Removed '.$seeds->count().' seeded account(s) and their landlord login rows.');

        return true;
    }
}
