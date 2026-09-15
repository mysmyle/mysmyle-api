<?php

namespace App\Console\Legacy;

/**
 * legacy.appointmentlog (~40 station columns) -> appointment_processes
 *
 * Seven separate commands in the mysmyleerp reference build — checkin, triage,
 * beingseen, checkout, discharge, completed — each re-reading all 135,773 rows.
 * Here it is one pass, because every one of them is the same shape:
 * (appointment, what happened, who did it, when).
 *
 * Process type ids are seeded by AppointmentModuleSeeder and are load-bearing.
 * Fill rates measured live on mysmyleadmin_vdc 2026-09-15:
 *
 *   1 checkin       Appointment_status='04-checked in', check_in_date +
 *                   Check_in_Time, checkin_by                       49,515
 *   2 triage_start  is_triage_start (a datetime), is_triage_start_by 24,595
 *   3 triage_end    is_triage_end, is_triage_end_by                 25,386
 *   4 beingseen     start_date + Start_time, seen_by                47,375
 *   5 checkout      clinic_forwarded_datetime, forwarded_by         47,008
 *   6 discharge     discharge, discharge_by / discharge_by_id       35,902
 *   7 complete      visit_completed_datetime, completed_by          46,469
 *
 * ── Two date columns, two formats, one column ────────────────────────────────
 *
 * `clinic_forwarded_datetime` is the worst column in the legacy database:
 * **24,720 rows hold 'Y-m-d H:i:s' and 22,285 hold '12-Jan-2022 17:32:43' — in
 * the same `text` column** — plus 3 in neither shape. Any range filter on it in
 * legacy is silently wrong. Carbon reads both, so both land as one DATETIME.
 *
 * `Check_in_Time` and `Start_time` hold a bare '16:04' with the date in a
 * separate column (`check_in_date`, `start_date`), which is why arrival
 * ordering across midnight is impossible in legacy. They are recombined here.
 *
 * The reference importer builds check-in as
 * `strtotime($appointment_date . $Check_in_Time)` — the SCHEDULED date
 * concatenated to the time with no separator. That is wrong twice over: the
 * strings run together ('2026-09-20' . '16:04' = '2026-09-2016:04'), and the
 * date should be `check_in_date`, which is when they actually arrived, not when
 * they were due.
 *
 * ── An event with no time is not imported ────────────────────────────────────
 *
 * `appointment_processes.occurred_at` is NOT NULL, and an undated event is not
 * a record of anything. Those rows are reported instead of being stamped with
 * the import time.
 */
class LegacyAppointmentProcessImporter extends BaseLegacyImporter
{
    private const CHECKIN = 1;

    private const TRIAGE_START = 2;

    private const TRIAGE_END = 3;

    private const BEINGSEEN = 4;

    private const CHECKOUT = 5;

    private const DISCHARGE = 6;

    private const COMPLETE = 7;

    /** Legacy value marking an actual arrival. */
    private const CHECKED_IN = '04-checked in';

    private array $validAppointmentIds = [];

    private LegacyActorResolver $actors;

    /** process_type_id => rows written, for the summary. */
    private array $perType = [];

    public function label(): string
    {
        return 'appointment processes (station events)';
    }

    public function run(): void
    {
        $this->validAppointmentIds = $this->tenant()->table('appointments')->pluck('id')->flip()->toArray();
        $this->actors = new LegacyActorResolver($this->legacy(), $this->tenant());

        $batch = [];

        foreach ($this->legacy()->table('appointmentlog')->orderBy('Appointment_id')->lazyById(500, 'Appointment_id') as $row) {
            $this->read++;

            $id = (int) $row->Appointment_id;

            // The appointment itself may have been skipped; its events cannot
            // be attached to anything.
            if (! isset($this->validAppointmentIds[$id])) {
                continue;
            }

            foreach ($this->eventsFor($row) as $event) {
                $batch[] = $event;
                $this->written++;
                $this->perType[$event['process_type_id']] = ($this->perType[$event['process_type_id']] ?? 0) + 1;
            }

            if (\count($batch) >= 500) {
                $this->flush($batch);
                $batch = [];
            }
        }

        $this->flush($batch);
        $this->report();
    }

    private function flush(array $batch): void
    {
        // UNIQUE(appointment_id, process_type_id) makes this a true upsert —
        // the reference simulates it with a read-then-write exists() check per
        // row, which is both slower and racy.
        $this->upsertBatch('appointment_processes', $batch, ['appointment_id', 'process_type_id'], [
            'user_id', 'occurred_at', 'remarks', 'updated_at',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function eventsFor(object $row): array
    {
        $id = (int) $row->Appointment_id;
        $now = now()->toDateTimeString();
        $events = [];

        $add = function (int $type, ?string $at, ?int $userId, ?string $remarks = null) use (&$events, $id, $now): void {
            if ($at === null) {
                return;
            }

            $events[] = [
                'appointment_id' => $id,
                'process_type_id' => $type,
                'user_id' => $userId,
                'occurred_at' => $at,
                'remarks' => $remarks,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        };

        // The day the appointment was booked for. Needed as the date half of
        // check-in and being-seen — see combine().
        $appointmentDate = $this->parseLegacyDate($row->appointment_date ?? null);

        // 1 — check-in. Only for rows legacy actually marks as arrived; the
        // other Appointment_status values are cancellations and belong to
        // confirmation_raps, not here.
        if (trim((string) ($row->Appointment_status ?? '')) === self::CHECKED_IN) {
            $at = $this->combine($row->check_in_date ?? null, $row->Check_in_Time ?? null, $appointmentDate);

            if ($at === null) {
                $this->skip($id, 'checked in but no usable Check_in_Time');
            }

            // `pre_ttt_remark` is the note taken at the desk. When the status is
            // a CANCELLATION it travels with the phase-5 confirmation_raps row;
            // when the patient actually arrived there is no rap, so without
            // this it had nowhere to go — 922 real check-in notes were being
            // dropped while the column that should hold them sat empty.
            $add(self::CHECKIN, $at, $this->actors->byName($row->checkin_by ?? null),
                $this->text($row->pre_ttt_remark ?? null));
        }

        // 2, 3 — triage. Already full datetimes, and the actor columns are
        // login ids rather than names.
        $add(self::TRIAGE_START, $this->parseLegacyDateTime($row->is_triage_start ?? null),
            $this->actors->byId($row->is_triage_start_by ?? null));

        $add(self::TRIAGE_END, $this->parseLegacyDateTime($row->is_triage_end ?? null),
            $this->actors->byId($row->is_triage_end_by ?? null));

        // 4 — being seen. Date and time in separate columns again.
        $add(self::BEINGSEEN, $this->combine($row->start_date ?? null, $row->Start_time ?? null, $appointmentDate),
            $this->actors->byName($row->seen_by ?? null));

        // 5 — checkout. The two-format column.
        $add(self::CHECKOUT, $this->parseLegacyDateTime($row->clinic_forwarded_datetime ?? null),
            $this->actors->byName($row->forwarded_by ?? null));

        // 6 — discharge. The only station where legacy writes both an id and a
        // name; the name is present far more often (35,631 vs 9,754).
        //
        // 4,517 rows hold another field's value in this column — '01-Sent'
        // (4,155) and '02-Not Applicable' (362) — rather than a timestamp.
        // Those are not discharges and get no event, but they are reported:
        // the patient may well have been discharged and legacy lost when.
        $dischargedAt = $this->parseLegacyDateTime($row->discharge ?? null);
        $dischargeRaw = trim((string) ($row->discharge ?? ''));

        if ($dischargedAt === null && $dischargeRaw !== '') {
            $this->skip($id, "discharge column holds '{$dischargeRaw}', not a timestamp");
        }

        $add(self::DISCHARGE, $dischargedAt,
            $this->actors->byIdOrName($row->discharge_by_id ?? null, $row->discharge_by ?? null));

        // 7 — completed.
        $add(self::COMPLETE, $this->parseLegacyDateTime($row->visit_completed_datetime ?? null),
            $this->actors->byName($row->completed_by ?? null));

        return $events;
    }

    /**
     * Rebuild one instant from legacy's separate date and time columns, falling
     * back to the appointment's own date when the dedicated date column is
     * empty.
     *
     * THE FALLBACK IS THE DIFFERENCE BETWEEN 4,815 EVENTS AND 43,930.
     * `check_in_date` and `start_date` were added to the schema late, so they
     * are NULL on the overwhelming majority of historical rows while the time
     * column is filled:
     *
     *                        rows marked checked in       43,937
     *                        check_in_date is NULL        39,122
     *                        Check_in_Time is filled      43,935
     *
     *                        rows with Start_time         47,384
     *                        start_date is NULL           38,011
     *
     * Refusing to fall back discards 39,122 real check-ins and 38,011 real
     * treatment starts — and the whole point of this table is those events.
     *
     * The fallback is measured, not assumed. Where BOTH columns exist:
     *
     *   check_in_date = appointment_date   4,809 of 4,815   (99.88%)
     *   start_date    = appointment_date   9,359 of 9,373   (99.85%)
     *
     * Which is what one would expect — you check in on the day of your
     * appointment. The dedicated column still wins whenever it is present, so
     * the 6 and 14 genuine same-day exceptions keep their real date.
     *
     * A time with no date at all still returns null; that cannot be placed on a
     * calendar and is not invented.
     */
    private function combine(mixed $date, mixed $time, ?string $fallbackDate): ?string
    {
        $t = trim((string) $time);

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $t, $m) !== 1) {
            return null;
        }

        // Legacy stores times like '24:31' — an hour that does not exist. MySQL
        // rejects it outright, so without this guard one such row aborts the
        // whole import. It is not silently rewritten to 00:31: which day that
        // would belong to is a guess.
        if ((int) $m[1] > 23 || (int) $m[2] > 59 || (int) ($m[3] ?? 0) > 59) {
            return null;
        }

        $raw = trim((string) $date);
        $day = str_starts_with($raw, '0000-00-00') ? null : $this->parseLegacyDate($raw);
        $day ??= $fallbackDate;

        if ($day === null) {
            return null;
        }

        return \sprintf('%s %02d:%02d:%02d', $day, (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function report(): void
    {
        $labels = [
            self::CHECKIN => 'checkin', self::TRIAGE_START => 'triage_start',
            self::TRIAGE_END => 'triage_end', self::BEINGSEEN => 'beingseen',
            self::CHECKOUT => 'checkout', self::DISCHARGE => 'discharge',
            self::COMPLETE => 'complete',
        ];

        foreach ($labels as $type => $label) {
            $this->info(str_pad($label, 14).($this->perType[$type] ?? 0));
        }
    }
}
