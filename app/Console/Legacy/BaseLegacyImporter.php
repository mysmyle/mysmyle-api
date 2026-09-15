<?php

namespace App\Console\Legacy;

use Illuminate\Console\OutputStyle;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared plumbing for the one-time legacy VDC import.
 *
 * Each importer reads from the read-only `legacy` connection and writes to the
 * `tenant` connection, which the calling command has already bound via
 * TenantConnectionResolver. Importers are idempotent: re-running one refreshes
 * legacy-owned columns and leaves app-owned columns (passwords, roles,
 * permissions, audit trails) untouched.
 */
abstract class BaseLegacyImporter
{
    protected OutputStyle $output;

    /** Human-readable skip reasons collected during a run, for the summary table. */
    protected array $skipped = [];

    /** Counters shown in the summary table. */
    protected int $read = 0;

    protected int $written = 0;

    public function __construct(protected bool $dryRun, protected int $tenantId) {}

    /** Short label used in command output, e.g. "staff". */
    abstract public function label(): string;

    abstract public function run(): void;

    public function setOutput(OutputStyle $output): void
    {
        $this->output = $output;
    }

    /** @return array{read: int, written: int, skipped: int} */
    public function stats(): array
    {
        return ['read' => $this->read, 'written' => $this->written, 'skipped' => count($this->skipped)];
    }

    /** @return array<int, array{0: string, 1: string}> id + reason pairs */
    public function skippedRows(): array
    {
        return $this->skipped;
    }

    protected function legacy(): Connection
    {
        return DB::connection('legacy');
    }

    protected function tenant(): Connection
    {
        return DB::connection('tenant');
    }

    protected function landlord(): Connection
    {
        return DB::connection('landlord');
    }

    protected function skip(string|int $id, string $reason): void
    {
        $this->skipped[] = [(string) $id, $reason];
    }

    protected function info(string $message): void
    {
        $this->output->writeln("    <info>{$message}</info>");
    }

    /**
     * Insert-or-update a batch keyed on $uniqueBy, overwriting only $update.
     *
     * Columns left off $update are never touched by a re-run — that is what lets
     * app-owned values (users.password once a staff member has set it, role_id
     * once an admin has assigned it) survive alongside legacy-owned columns on
     * the same row.
     */
    protected function upsertBatch(string $table, array $rows, array $uniqueBy, array $update): void
    {
        if ($this->dryRun || empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, 500) as $slice) {
            $this->tenant()->table($table)->upsert($slice, $uniqueBy, $update);
        }
    }

    /**
     * Return $candidate only if it exists in $validIds, else null.
     *
     * Legacy tables reference raw ids (a login_id, an employee_id) with no
     * constraint behind them, so a reference can point at a row that was never
     * imported — or never existed. Checking up front keeps the insert clean
     * instead of discovering it as a foreign-key failure.
     */
    protected function validId(mixed $candidate, array $validIds): ?int
    {
        if (empty($candidate)) {
            return null;
        }

        return isset($validIds[(int) $candidate]) ? (int) $candidate : null;
    }

    /** Parse a legacy date-only value; null if empty or unparseable. */
    protected function parseLegacyDate(?string $value): ?string
    {
        return $this->parseLegacyCarbon($value)?->toDateString();
    }

    /** Parse a legacy datetime value; null if empty or unparseable. */
    protected function parseLegacyDateTime(?string $value): ?string
    {
        return $this->parseLegacyCarbon($value)?->toDateTimeString();
    }

    /**
     * Parse a legacy date/datetime string, treating MySQL "zero dates"
     * ('0000-00-00', '0000-00-00 00:00:00') as invalid rather than handing them
     * to Carbon::parse().
     *
     * PHP's DateTime does NOT throw on a zero date — it rolls month/day 00 under
     * to '-0001-11-30'. That is a perfectly valid Carbon instance, so it sails
     * through any try/catch and only fails later when a strict-mode connection
     * rejects the write. This is not hypothetical: 137 of 255 rows in the legacy
     * `login.updated_date` column are zero dates, and this exact bug broke the
     * previous system's live sync.
     */
    private function parseLegacyCarbon(?string $value): ?Carbon
    {
        $raw = trim((string) $value);

        if ($raw === '' || $raw === 'NULL' || preg_match('/^0{4}-0{2}-0{2}/', $raw) === 1) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
