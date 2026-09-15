<?php

namespace App\Console\Legacy;

/**
 * legacy.doctors_log -> doctors
 *
 * Sixteen rows, and every appointment depends on them: `appointmentlog.Doctor`
 * holds a `doctors_log.doctor_id`, so this must run before the appointments.
 *
 * KEY: doctors.id IS the legacy doctor_id. Preserving it is what lets
 * LegacyAppointmentImporter write `doctor_id` straight from the legacy column
 * with no translation.
 *
 * Verified live 2026-09-15: all 16 rows carry an `employee_id` that exists in
 * `employee_register`, and no two share one — which matters because
 * `doctors.staff_id` is UNIQUE and NOT NULL.
 *
 * `status`: 7 rows are 'Y' and 9 are 'N'. The inactive ones are kept, not
 * dropped — 7,536 appointments belong to doctors who have since left, and
 * discarding the doctor would discard their history with it.
 *
 * `display_name` is the short calendar label ("Dr. Emadeldin"); the full legal
 * name lives on the staff record and is not duplicated here. Where legacy left
 * `display_name` blank the longer `dr_name` is used, because a doctor with no
 * label at all cannot be rendered in the diary.
 *
 * NOTE — doctor_id 5 is "Dr. Test" (409 appointments, status 'N'). It is
 * imported as an ordinary inactive doctor. What this importer does NOT do is
 * what the mysmyleerp reference importer does: use 5 as the fallback for an
 * unreadable `Doctor` value (`doctor_id ?: 5`), which silently files real
 * patients' appointments under the test doctor.
 */
class LegacyDoctorImporter extends BaseLegacyImporter
{
    public function label(): string
    {
        return 'doctors';
    }

    public function run(): void
    {
        $validStaffIds = $this->tenant()->table('staff')->pluck('id')->flip()->toArray();

        $batch = [];
        $seenStaff = [];
        $now = now()->toDateTimeString();

        foreach ($this->legacy()->table('doctors_log')->orderBy('doctor_id')->get() as $row) {
            $this->read++;

            $staffId = $this->validId($row->employee_id ?? null, $validStaffIds);

            if ($staffId === null) {
                // doctors.staff_id is NOT NULL: a doctor with no staff record
                // cannot be represented, and inventing one would create a
                // person who does not exist.
                $this->skip($row->doctor_id, "employee_id '{$row->employee_id}' is not an imported staff member");

                continue;
            }

            if (isset($seenStaff[$staffId])) {
                $this->skip($row->doctor_id, "staff {$staffId} already used by doctor {$seenStaff[$staffId]} — doctors.staff_id is UNIQUE");

                continue;
            }

            $seenStaff[$staffId] = (int) $row->doctor_id;

            $displayName = trim((string) ($row->display_name ?? ''));

            if ($displayName === '') {
                $displayName = trim((string) ($row->dr_name ?? ''));
                $this->skip($row->doctor_id, "blank display_name — used dr_name '{$displayName}'");
            }

            if ($displayName === '') {
                $this->skip($row->doctor_id, 'no display_name and no dr_name — cannot label this doctor');

                continue;
            }

            $license = trim((string) ($row->doctor_license ?? ''));

            $batch[] = [
                'id' => (int) $row->doctor_id,
                'staff_id' => $staffId,
                'display_name' => $displayName,
                'license_number' => $license !== '' ? $license : null,
                // 'Y' is the only value legacy treats as active; the booking
                // form filters doctors_log on status='Y'.
                'status' => strtoupper(trim((string) ($row->status ?? ''))) === 'Y' ? 'active' : 'inactive',
                // doctors_log has no created/updated columns.
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $this->written++;
        }

        $this->upsertBatch('doctors', $batch, ['id'], [
            'staff_id', 'display_name', 'license_number', 'status', 'updated_at',
        ]);
    }
}
