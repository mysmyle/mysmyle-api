<?php

namespace App\Console\Legacy;


/**
 * legacy.appointmentlog -> appointments
 *
 * KEY: appointments.id IS the legacy Appointment_id. Every child importer
 * (processes, confirmations, reschedules, tentatives) re-finds its parent by
 * that id, so none of them needs a crosswalk table.
 *
 * Every rule below was measured against the live legacy database on
 * 2026-09-15. Where a number appears, it came from a query.
 *
 * ── Nothing is invented ──────────────────────────────────────────────────────
 *
 * A row that cannot be mapped is SKIPPED and reported, never defaulted. This is
 * the main departure from the mysmyleerp reference importer, which substitutes
 * a value four times over:
 *
 *   `$oldAppointment->Doctor ?: 5`        files the appointment under doctor 5,
 *                                         "Dr. Test" — 51 rows
 *   `normalizeDate()` -> '2017-12-31'      invents a date for empty ones
 *   `normalizeTime()` -> '00:00:00'        invents midnight — 71 rows
 *   `$userId ?? 999`                       invents a user — 15,422 rows
 *
 * Each of those produces a row that looks real and is not. A skipped row can be
 * found and fixed at source; a defaulted one cannot.
 *
 * ── What imports ─────────────────────────────────────────────────────────────
 *
 * Of 135,773 legacy rows, 134,998 reference a chart that exists in `patients`
 * and 795 do not (736 with a blank Chart, plus 28 charts never registered).
 *
 * Chart 2 — "Blocked Appointment / Dr Break" — carries **15,515 rows, 11% of
 * the table**. These are doctors' breaks that legacy stores as fake
 * appointments via dr_block_apt_functions_v2.php. They import as ordinary
 * appointments on that patient, exactly as legacy holds them, and are counted
 * separately in the run summary so nobody mistakes them for real bookings.
 * Modelling availability properly is a separate piece of work and needs data
 * (working hours, chairs) that exists in neither system.
 *
 * ── KNOWN RESIDUAL: 657 uncertain `created_at` values ────────────────────────
 *
 * Legacy wrote `created_date` in year-DAY-month on part of appointment id block
 * 32957-36988 — see BaseLegacyImporter::parseUnreliableOrderDateTime(). Within
 * that block of 2,726 rows the outcome is:
 *
 *   1,792  month component > 12  -> provably Y-d-m, swapped      CERTAIN
 *      48  day component   > 12  -> provably Y-m-d, kept          CERTAIN
 *     104  month = day           -> order-invariant               CERTAIN
 *     125  settled by the updated_date witness, swapped           CERTAIN
 *     657  nothing settles them, left as written              ** UNCERTAIN **
 *
 * Of those 657, the evidence suggests roughly 600 are still month/day reversed:
 * inside this block the witness confirms the as-is reading on 0 of 643 rows
 * that have one, against a ~9-36% confirmation rate everywhere else in the
 * table, and the witness sits nearer the swapped reading on 597 of 643.
 *
 * They are deliberately NOT corrected. That evidence is statistical, not
 * per-row: acting on it would rewrite ~46 rows that are demonstrably already
 * right in order to guess at the rest, and `created_at` here is provenance
 * metadata, not clinical or scheduling data. Nothing outside this block and no
 * other column is affected — verified across all eleven date columns in the
 * module.
 *
 * If the clinic ever needs these exact, the answer is not in the database; it
 * would have to come from an application log or a backup predating the defect.
 */
class LegacyAppointmentImporter extends BaseLegacyImporter
{
    /** appointment_statuses ids, seeded by AppointmentModuleSeeder. */
    private const STATUS_TENTATIVE = 1;

    private const STATUS_BOOKED = 2;

    private const STATUS_MOVED = 3;

    private const STATUS_RESCHEDULED = 4;

    private const STATUS_ARCHIVED = 5;

    private const STATUS_REMOVED = 6;

    /** legacy appointmentlog.status -> appointment_statuses id. */
    private const LEGACY_STATUS_MAP = [
        'Y' => self::STATUS_BOOKED,        // 121,713 rows — live
        'A' => self::STATUS_ARCHIVED,      //   1,829
        'F' => self::STATUS_REMOVED,       //   1,575
        'N' => self::STATUS_REMOVED,       //   4,448 — legacy soft delete
        'waiting-list' => self::STATUS_REMOVED, // 6,194 — abandoned queue marker
    ];

    /** The blocked-time pseudo-patient. Counted, not hidden. */
    private const BLOCK_CHART = 2;

    private array $patientsByChart = [];

    private array $validDoctorIds = [];

    private array $typeIdsByName = [];

    private array $validTypeIds = [];

    private int $blockRows = 0;

    /** created_date rows whose month/day order was corrected. */
    private int $correctedCreatedDates = 0;

    private LegacyActorResolver $actors;

    public function label(): string
    {
        return 'appointments';
    }

    public function run(): void
    {
        $this->loadReferenceData();

        $batch = [];

        foreach ($this->legacy()->table('appointmentlog')->orderBy('Appointment_id')->lazyById(500, 'Appointment_id') as $row) {
            $this->read++;

            $mapped = $this->mapAppointment($row);

            if ($mapped === null) {
                continue;
            }

            $batch[] = $mapped;
            $this->written++;

            if (\count($batch) >= 500) {
                $this->flush($batch);
                $batch = [];
            }
        }

        $this->flush($batch);

        $this->info("blocked-time rows (chart 2, doctors' breaks stored as appointments): {$this->blockRows}");

        if ($this->correctedCreatedDates > 0) {
            $this->info("created_date rows whose month/day order was corrected (legacy wrote "
                ."Y-d-m on part of one id block): {$this->correctedCreatedDates}");
        }

        if ($ambiguous = $this->actors->ambiguousNames()) {
            $this->info(\count($ambiguous).' actor name(s) belong to more than one legacy login — lowest login id used.');
        }
    }

    private function flush(array $batch): void
    {
        // `created_at` IS in the update list, unlike the identity importers.
        // On staff and users it is app-owned once the row exists; here it is
        // purely legacy-derived — the application does not create appointments
        // yet, and every row in this batch came from appointmentlog. Leaving it
        // out means a corrected created_date can never reach a row that was
        // already imported, which is exactly what happened when the month/day
        // repair was first run.
        $this->upsertBatch('appointments', $batch, ['id'], [
            'patient_id', 'doctor_id', 'appointment_type_id', 'appointment_status_id',
            'user_id', 'appointment_date', 'appointment_time', 'treatment_duration',
            'appointment_note', 'created_at', 'updated_at',
        ]);
    }

    private function loadReferenceData(): void
    {
        $this->patientsByChart = $this->tenant()->table('patients')
            ->pluck('id', 'chart')->map(fn ($id) => (int) $id)->toArray();

        $this->validDoctorIds = $this->tenant()->table('doctors')->pluck('id')->flip()->toArray();

        // Keyed on the SAME normalisation the type importer used to create
        // them. Matching on the raw label instead would fail for the 292
        // appointments whose label carries a trailing newline or a doubled
        // space, and for the 12 label pairs that differ only by an en-dash.
        foreach ($this->tenant()->table('appointment_types')->get(['id', 'name']) as $type) {
            $this->typeIdsByName[LegacyAppointmentTypeImporter::matchKey($type->name)] = (int) $type->id;
        }

        $this->validTypeIds = $this->tenant()->table('appointment_types')->pluck('id')->flip()->toArray();

        $this->actors = new LegacyActorResolver($this->legacy(), $this->tenant());
    }

    private function mapAppointment(object $row): ?array
    {
        $id = (int) $row->Appointment_id;

        // ── Patient ──────────────────────────────────────────────────────────
        $chart = trim((string) ($row->Chart ?? ''));

        if ($chart === '') {
            $this->skip($id, 'blank Chart — no patient to attach to');

            return null;
        }

        $patientId = $this->patientsByChart[$chart] ?? null;

        if ($patientId === null) {
            $this->skip($id, "chart {$chart} was never imported as a patient");

            return null;
        }

        if ((int) $chart === self::BLOCK_CHART) {
            $this->blockRows++;
        }

        // ── Doctor ───────────────────────────────────────────────────────────
        // NOT defaulted to 5. Doctor 5 is "Dr. Test".
        $doctorId = $this->validId($row->Doctor ?? null, $this->validDoctorIds);

        if ($doctorId === null) {
            $this->skip($id, "Doctor '{$row->Doctor}' is empty or not an imported doctor");

            return null;
        }

        // ── Type ─────────────────────────────────────────────────────────────
        $typeId = $this->resolveType($row);

        if ($typeId === null) {
            $this->skip($id, 'no resolvable appointment type');

            return null;
        }

        // ── When ─────────────────────────────────────────────────────────────
        $date = $this->parseAppointmentDate($row->appointment_date ?? null);

        if ($date === null) {
            $this->skip($id, "unparseable appointment_date '{$row->appointment_date}'");

            return null;
        }

        $time = $this->parseAppointmentTime($row->Appointment_time ?? null);

        if ($time === null) {
            $this->skip($id, 'empty or unparseable Appointment_time — not defaulted to midnight');

            return null;
        }

        // ── Duration ─────────────────────────────────────────────────────────
        $duration = $this->correctDuration($row->treatment_duration ?? null);

        if ($duration === null) {
            $this->skip($id, "unusable treatment_duration '{$row->treatment_duration}'");

            return null;
        }

        // `created_date` is the one column in this module whose month/day order
        // is unreliable — see parseUnreliableOrderDateTime(). Parsing it with
        // the ordinary helper loses 1,792 rows to NULL and silently misreads
        // several hundred more by up to eleven months.
        [$createdAt, $orderCorrected] = $this->parseUnreliableOrderDateTime(
            $row->created_date ?? null,
            $row->updated_date ?? null,
        );

        if ($orderCorrected) {
            $this->correctedCreatedDates++;
        }

        return [
            'id' => $id,
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'appointment_type_id' => $typeId,
            'appointment_status_id' => $this->resolveStatus($row),
            'user_id' => $this->actors->byName($row->created_by ?? null),
            'appointment_date' => $date,
            'appointment_time' => $time,
            'treatment_duration' => $duration,
            'appointment_note' => $this->text($row->Appointment_note ?? null),
            // 4,129 rows have no created_date. Laravel timestamps are nullable,
            // so "unknown" stays unknown rather than becoming the import date.
            'created_at' => $createdAt,
            'updated_at' => $this->parseLegacyDateTime($row->updated_date ?? null) ?? $createdAt,
        ];
    }

    /**
     * The booked type, by label, falling back to the clinically verified id.
     *
     * `Appointment_type` is the label as it was at booking time; 10,878 rows
     * name a retired label, which LegacyAppointmentTypeImporter has recreated,
     * so those resolve here. 272 rows have no label at all and fall back to
     * `visit_appointment_type`, the verified type stored as an id.
     */
    private function resolveType(object $row): ?int
    {
        $key = LegacyAppointmentTypeImporter::matchKey($row->Appointment_type ?? null);

        if ($key !== '' && isset($this->typeIdsByName[$key])) {
            return $this->typeIdsByName[$key];
        }

        return $this->validId($row->visit_appointment_type ?? null, $this->validTypeIds);
    }

    /**
     * Status precedence, following the reference build's own order:
     *
     *   1. resched_status 'reschedule' | 'rebook' -> rescheduled  (22,468 rows)
     *   2. resched_status 'move'                  -> moved        (12,994)
     *   3. otherwise the legacy `status` letter                   (see the map)
     *   4. is_tentative = 1 overrides everything   -> tentative    (4,654)
     *
     * Step 4 is last on purpose: a tentative that was later rescheduled is still
     * a tentative as far as the diary is concerned, which is how legacy's own
     * feed renders it.
     */
    private function resolveStatus(object $row): int
    {
        if ((int) ($row->is_tentative ?? 0) === 1) {
            return self::STATUS_TENTATIVE;
        }

        $resched = strtolower(trim((string) ($row->resched_status ?? '')));

        if ($resched === 'reschedule' || $resched === 'rebook') {
            return self::STATUS_RESCHEDULED;
        }

        if ($resched === 'move') {
            return self::STATUS_MOVED;
        }

        return self::LEGACY_STATUS_MAP[trim((string) ($row->status ?? ''))] ?? self::STATUS_BOOKED;
    }

    /**
     * Parse `appointment_date`, a varchar(150) holding two real formats.
     *
     * Measured live: 135,655 rows 'Y-m-d', **98 rows 'd-m-Y'**, 19 empty, 1
     * holding a full datetime. The d-m-Y rows are why MAX(appointment_date)
     * returns '30-12-2020' in legacy — the column is compared as a string.
     *
     * Order matters. 'd-m-Y' is tried explicitly rather than left to
     * Carbon::parse(), which reads a slash/dash date as US month-day and would
     * swap day and month on every one of those 98 rows.
     */
    private function parseAppointmentDate(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        // DateTimeImmutable, not Carbon: Carbon::createFromFormat() THROWS on a
        // mismatch instead of returning false, so a `!== false` guard never
        // fires and the first 'd-m-Y' row aborts the whole import.
        foreach (['Y-m-d', 'd-m-Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format.'|', $value);

            // The round-trip check rejects PHP's lenient overflow, which would
            // otherwise read '2021-13-45' as a date in 2022.
            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->format('Y-m-d');
            }
        }

        // The single row holding 'Y-m-d H:i:s'.
        return $this->parseLegacyDate($value);
    }

    /**
     * Parse `Appointment_time`, a varchar(256).
     *
     * Measured live: 132,136 rows 'H:i', 3,566 'H:i:s', 20 with a single-digit
     * hour, 71 empty. Empty returns NULL so the caller skips the row — legacy's
     * own form requires a time, so a blank one is a broken record, not an
     * appointment at midnight.
     */
    private function parseAppointmentTime(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m) !== 1) {
            return null;
        }

        [$hour, $minute, $second] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];

        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }

    /**
     * Undo the legacy (15 * n) - 1 dropdown — conditionally.
     *
     * `bookings_v17.php` builds the Unit/s options as (15 * $i) - 1, so a
     * 30-minute appointment stores 29. That keeps a 09:00-09:29 block from
     * visually touching the 09:30 slot in FullCalendar. Measured live:
     *
     *   one short (14, 29, 44 ...)  108,226 rows   -> +1
     *   exact     (15, 30, 45 ...)   26,715 rows   -> leave alone
     *   other                           618 rows   -> leave alone
     *   zero or negative                147 rows   -> skip (132 are chart-2
     *                                                  blocks saved at -1)
     *   empty                            67 rows   -> skip
     *
     * The reference importer adds 1 to all of them, which corrects the 108,226
     * and breaks the 26,715 that were already right.
     *
     * Values above 480 are kept, not clamped: 1,803 rows exceed it and the
     * longest is 1,409 minutes, which for a chart-2 row is a doctor blocking
     * out a whole day — a real thing to record.
     */
    private function correctDuration(mixed $raw): ?int
    {
        $value = trim((string) $raw);

        if ($value === '' || ! preg_match('/^-?\d+$/', $value)) {
            return null;
        }

        $minutes = (int) $value;

        if ($minutes <= 0) {
            return null;
        }

        return $minutes % 15 === 14 ? $minutes + 1 : $minutes;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
