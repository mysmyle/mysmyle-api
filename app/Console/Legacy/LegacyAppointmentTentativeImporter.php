<?php

namespace App\Console\Legacy;

/**
 * legacy.appointmentlog_list -> appointment_tentatives
 *
 * 5,821 rows, latin1_swedish_ci — a third collation in the same module.
 *
 * KEY: appointment_tentatives.id IS the legacy aplist_id.
 *
 * ── `aplist_status` carries four states, not one ─────────────────────────────
 *
 * Measured live 2026-09-15, mapped to appointment_statuses:
 *
 *   0  active   2,530  -> 1 tentative  (still waiting)
 *   1  booked   2,483  -> 2 booked     (became a real appointment)
 *   2  moved      768  -> 3 moved
 *   3  removed     40  -> 6 removed
 *
 * The reference importer hard-codes `appointment_status_id => 2` on every row
 * it writes, collapsing all four into "booked" — so 3,338 entries claim to have
 * been booked when 2,530 of them are still waiting and 40 were withdrawn.
 *
 * ── Why this importer reads the list table, not appointmentlog ───────────────
 *
 * The reference iterates `appointmentlog`, filters on `is_tentative`, and then
 * looks up the matching `appointmentlog_list` row per appointment — an N+1
 * against 135,773 rows to find 5,821, and it silently requires `new_appid` to
 * be set, so only tentatives that already resolved are written at all.
 *
 * Reading the list table directly is one pass and keeps the 2,530 unresolved
 * entries, which are the live queue and the whole point of the table.
 *
 * ── The two representations legacy could not reconcile ───────────────────────
 *
 * `appointmentlog.is_tentative = 1` gives 4,654 rows; `appointmentlog_list`
 * gives 5,821. Promoting a tentative clears the flag but leaves the list row,
 * so the two never agree. In the new schema the flag is gone: an appointment is
 * tentative when its status is 1, and this table is the queue's own record.
 */
class LegacyAppointmentTentativeImporter extends BaseLegacyImporter
{
    /** legacy aplist_status => appointment_statuses id. */
    private const STATUS_MAP = [
        0 => 1,  // active  -> tentative
        1 => 2,  // booked  -> booked
        2 => 3,  // moved   -> moved
        3 => 6,  // removed -> removed
    ];

    private array $validAppointmentIds = [];

    /** legacy Appointment_id => new_appid, for rows that have a real successor. */
    private array $successors = [];

    private LegacyActorResolver $actors;

    public function label(): string
    {
        return 'appointment tentatives';
    }

    public function run(): void
    {
        $this->validAppointmentIds = $this->tenant()->table('appointments')->pluck('id')->flip()->toArray();
        $this->actors = new LegacyActorResolver($this->legacy(), $this->tenant());
        $this->loadSuccessors();

        $batch = [];
        $seenAppointments = [];
        $now = now()->toDateTimeString();

        foreach ($this->legacy()->table('appointmentlog_list')->orderBy('aplist_id')->lazyById(500, 'aplist_id') as $row) {
            $this->read++;

            $appointmentId = (int) ($row->aplist_appid ?? 0);

            if (! isset($this->validAppointmentIds[$appointmentId])) {
                $this->skip($row->aplist_id, "appointment {$appointmentId} was not imported");

                continue;
            }

            // UNIQUE(appointment_id): one queue entry per tentative. Rows arrive
            // in id order, so the first sighting is the earliest and wins.
            if (isset($seenAppointments[$appointmentId])) {
                $this->skip($row->aplist_id, "appointment {$appointmentId} already has entry {$seenAppointments[$appointmentId]}");

                continue;
            }

            $seenAppointments[$appointmentId] = (int) $row->aplist_id;

            $legacyStatus = (int) ($row->aplist_status ?? 0);
            $statusId = self::STATUS_MAP[$legacyStatus] ?? null;

            if ($statusId === null) {
                $this->skip($row->aplist_id, "unknown aplist_status '{$legacyStatus}'");

                continue;
            }

            // aplist_cdate / aplist_ctime are when the entry was CREATED.
            $occurredAt = $this->combine($row->aplist_cdate ?? null, $row->aplist_ctime ?? null);

            if ($occurredAt === null) {
                $this->skip($row->aplist_id, 'no usable aplist_cdate/aplist_ctime');

                continue;
            }

            $batch[] = [
                'id' => (int) $row->aplist_id,
                'appointment_id' => $appointmentId,
                // Only set once the queue entry actually produced a booking.
                'new_appointment_id' => $this->resolveSuccessor($appointmentId, $legacyStatus),
                'appointment_status_id' => $statusId,
                // aplist_by is a login id, not a name — and holds values up to
                // 186000000, so it is validated rather than trusted.
                'user_id' => $this->actors->byId($row->aplist_by ?? null),
                'remarks' => $this->text($row->aplist_notes ?? null),
                'occurred_at' => $occurredAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $this->written++;

            if (\count($batch) >= 500) {
                $this->flush($batch);
                $batch = [];
            }
        }

        $this->flush($batch);
    }

    private function flush(array $batch): void
    {
        $this->upsertBatch('appointment_tentatives', $batch, ['id'], [
            'appointment_id', 'new_appointment_id', 'appointment_status_id',
            'user_id', 'remarks', 'occurred_at', 'updated_at',
        ]);
    }

    /**
     * The appointment this tentative turned into.
     *
     * Only meaningful once the entry left the queue; while it is still active
     * (`aplist_status = 0`) there is by definition no successor, and legacy's
     * `new_appid` on the source row is the chain pointer for the appointment,
     * not for the queue entry.
     */
    private function resolveSuccessor(int $appointmentId, int $legacyStatus): ?int
    {
        if ($legacyStatus === 0) {
            return null;
        }

        return $this->successors[$appointmentId] ?? null;
    }

    /**
     * Preload every real successor in one query.
     *
     * `new_appid` says "nothing" in two different ways — 57,806 rows hold 0 and
     * 25,204 hold NULL — so the filter is `> 0`, not `IS NOT NULL`. Writing it
     * the obvious way would silently treat 57,806 zeroes as real appointment
     * ids.
     */
    private function loadSuccessors(): void
    {
        foreach (
            $this->legacy()->table('appointmentlog')
                ->where('new_appid', '>', 0)
                ->select(['Appointment_id', 'new_appid'])
                ->lazyById(1000, 'Appointment_id') as $row
        ) {
            $newId = (int) $row->new_appid;

            if (isset($this->validAppointmentIds[$newId])) {
                $this->successors[(int) $row->Appointment_id] = $newId;
            }
        }
    }

    private function combine(mixed $date, mixed $time): ?string
    {
        $d = $this->parseLegacyDate(trim((string) $date));

        if ($d === null) {
            return null;
        }

        $t = trim((string) $time);

        // An unusable or impossible clock value (legacy holds things like
        // '24:31') falls back to midnight on the recorded DAY, which is still
        // true — the entry was created that day.
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $t, $m) !== 1
            || (int) $m[1] > 23 || (int) $m[2] > 59 || (int) ($m[3] ?? 0) > 59) {
            return $d.' 00:00:00';
        }

        return \sprintf('%s %02d:%02d:%02d', $d, (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
