<?php

namespace App\Console\Legacy;

/**
 * legacy.employee_register -> tenant.staff
 *
 * Primary keys are preserved: staff.id is the legacy employee_id, so every
 * later import that references an employee joins without a translation table.
 *
 * Scope stops at `status` — the table has no columns beyond it.
 */
class LegacyStaffImporter extends BaseLegacyImporter
{
    /** Legacy staff_status values that mean "still with us". */
    private const ACTIVE_STATUSES = ['current_staff', 'under_process'];

    public function label(): string
    {
        return 'staff';
    }

    public function run(): void
    {
        $batch = [];
        $now = now()->toDateTimeString();
        $today = now()->toDateString();

        // lazyById streams with keyset pagination (WHERE id > ?) rather than
        // OFFSET, so cost stays flat as the table grows.
        foreach ($this->legacy()->table('employee_register')->lazyById(500, 'employee_id') as $row) {
            $this->read++;

            $name = trim((string) ($row->employee_name ?? ''));

            if ($name === '') {
                $name = 'Unknown';
                $this->skip($row->employee_id, 'blank employee_name — imported as "Unknown"');
            }

            $personalEmail = trim((string) ($row->email_address ?? ''));

            if ($personalEmail === '') {
                $this->skip($row->employee_id, 'no personal email — set-password link cannot be sent');
            }

            $gender = strtolower(trim((string) ($row->gender ?? '')));

            $dateOfBirth = $this->parseLegacyDate($row->date_of_birth ?? null);

            // Carried through as-is rather than silently corrected: legacy holds
            // typos like '0192-12-01' that parse cleanly but cannot be real.
            if ($dateOfBirth !== null && ($dateOfBirth < '1920-01-01' || $dateOfBirth > $today)) {
                $this->skip($row->employee_id, "implausible date_of_birth '{$dateOfBirth}' — imported unchanged, fix at source");
            }

            $batch[] = [
                'id' => (int) $row->employee_id,
                'name' => $name,
                'personal_email' => $personalEmail !== '' ? $personalEmail : null,
                'gender' => $gender !== '' ? $gender : null,
                'date_of_birth' => $dateOfBirth,
                'status' => in_array(trim((string) ($row->staff_status ?? '')), self::ACTIVE_STATUSES, true)
                    ? 'active'
                    : 'inactive',
                // Populated in only 8 of 175 legacy rows, and `added_date` is NULL
                // throughout, so there is no second source to fall back to.
                'created_at' => $this->parseLegacyDateTime($row->created_at ?? null) ?? $now,
                'updated_at' => $this->parseLegacyDateTime($row->updated_at ?? null) ?? $now,
            ];

            $this->written++;
        }

        $this->upsertBatch('staff', $batch, ['id'], [
            'name', 'personal_email', 'gender', 'date_of_birth', 'status', 'updated_at',
        ]);
    }
}
