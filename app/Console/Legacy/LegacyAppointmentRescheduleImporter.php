<?php

namespace App\Console\Legacy;

/**
 * legacy.appointmentlog_resched_details -> appointment_reschedules
 *
 * 42,408 rows, utf8mb3_unicode_ci while `appointmentlog` is utf8mb4 — the join
 * is numeric, so the collation mismatch does not bite here, but it is why no
 * string join between these two tables is safe.
 *
 * KEY: appointment_reschedules.id IS the legacy reschedule_id.
 *
 * ── `reschedule_checkbox` mixes codes with call notes ────────────────────────
 *
 * This is the whole difficulty. Measured live 2026-09-15:
 *
 *   05- Move                                              10,089   code
 *   04- Cancelled by PT                                    9,083   code
 *   05- Reschedule                                         7,359   code
 *   Currently speaking with the patient                    5,729   CALL NOTE
 *   Spoke to the patient (this night, this morning, ...)   2,768   CALL NOTE
 *   03- Cancelled by Center                                2,082   code
 *   Cancelled by PT                                        1,668   code, unnumbered
 *   00- Book                                               1,493   code
 *   09- Rescheduled By Center                                788
 *   06- Move                                                 722   'Move', renumbered
 *   08- Missed Reschedule                                    340
 *   Cancelled by Center                                      165   unnumbered again
 *   07- Cancel In Center                                      83
 *   Reschedule / Move / Cancelled by Patient              18/14/1
 *
 * **8,497 rows — a fifth of the table — hold a call note where a code belongs.**
 * The reference importer maps this column to a type id and `continue`s on
 * anything unmatched, so all 8,497 are dropped. Here an unmatched value keeps
 * its text in `remarks` and leaves `confirmation_type_id` NULL, which is the
 * honest record: somebody rescheduled and typed a note instead of picking a
 * reason.
 *
 * `00- Book` (1,493) is NOT mapped to 'moved' as the reference does. It is the
 * initial booking of a slot that had none — the opposite of a move.
 *
 * ── `kind` ───────────────────────────────────────────────────────────────────
 *
 * Legacy derives the kind by comparing the two dates rather than asking:
 * earlier is 'reschedule', same day is 'move', later is 'rebook'. It stores the
 * result on `appointmentlog.resched_status`, not on this table, so it is
 * recomputed here from `orig_date` and the new appointment's date — and left
 * NULL when either is unusable rather than guessed.
 *
 * ── The successor ────────────────────────────────────────────────────────────
 *
 * `resched_newid` is a `text` column: 35,911 rows numeric, 6,503 empty. Empty
 * means the reschedule never produced a replacement, which is a real outcome,
 * so `new_appointment_id` is nullable. The reference requires BOTH ids to exist
 * and skips otherwise, discarding every unreplaced cancellation.
 */
class LegacyAppointmentRescheduleImporter extends BaseLegacyImporter
{
    /**
     * reschedule_checkbox => confirmation_rap_types id.
     *
     * Anything not listed is treated as a call note, not an error.
     */
    private const VALUE_MAP = [
        '05- Move' => 7,
        '06- Move' => 7,
        'Move' => 7,
        '04- Cancelled by PT' => 5,
        'Cancelled by PT' => 5,
        'Cancelled by Patient' => 5,
        '03- Cancelled by Center' => 6,
        'Cancelled by Center' => 6,
        '05- Reschedule' => 10,   // "Reschedule" by the centre
        'Reschedule' => 10,
        '09- Rescheduled By Center' => 10,
        '07- Cancel In Center' => 8,
        '08- Missed Reschedule' => 9,
    ];

    private array $validAppointmentIds = [];

    private array $appointmentDates = [];

    private LegacyActorResolver $actors;

    private int $callNotes = 0;

    public function label(): string
    {
        return 'appointment reschedules';
    }

    public function run(): void
    {
        $this->validAppointmentIds = $this->tenant()->table('appointments')->pluck('id')->flip()->toArray();
        $this->appointmentDates = $this->tenant()->table('appointments')
            ->pluck('appointment_date', 'id')->toArray();
        $this->actors = new LegacyActorResolver($this->legacy(), $this->tenant());

        $batch = [];
        $now = now()->toDateTimeString();

        foreach ($this->legacy()->table('appointmentlog_resched_details')->orderBy('reschedule_id')->lazyById(500, 'reschedule_id') as $row) {
            $this->read++;

            $appointmentId = (int) $row->reschedule_appid;

            if (! isset($this->validAppointmentIds[$appointmentId])) {
                $this->skip($row->reschedule_id, "appointment {$appointmentId} was not imported");

                continue;
            }

            $newId = trim((string) ($row->resched_newid ?? ''));
            $newAppointmentId = ($newId !== '' && isset($this->validAppointmentIds[(int) $newId]))
                ? (int) $newId
                : null;

            $value = trim((string) ($row->reschedule_checkbox ?? ''));
            $typeId = self::VALUE_MAP[$value] ?? null;

            // Keep the call note, and the '00- Book' marker, as text.
            $remark = $this->text($row->reschedule_reason ?? null);

            if ($typeId === null && $value !== '' && $value !== 'NULL') {
                $this->callNotes++;
                $remark = $remark === null ? $value : $value.' — '.$remark;
            }

            $occurredAt = $this->combine($row->reschedule_date ?? null, $row->reschedule_time ?? null)
                ?? $this->parseLegacyDateTime($row->reschedule_date ?? null);

            if ($occurredAt === null) {
                $this->skip($row->reschedule_id, 'no usable reschedule_date');

                continue;
            }

            $originalDate = $this->parseLegacyDate($row->orig_date ?? null);

            $batch[] = [
                'id' => (int) $row->reschedule_id,
                'appointment_id' => $appointmentId,
                'new_appointment_id' => $newAppointmentId,
                'confirmation_type_id' => $typeId,
                'user_id' => $this->actors->byName($row->reschedule_by ?? null),
                'kind' => $this->resolveKind($originalDate, $newAppointmentId),
                'original_date' => $originalDate,
                'original_time' => $this->parseTime($row->orig_time ?? null),
                'remarks' => $remark,
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

        $this->info("rows whose checkbox held a call note rather than a code, kept as remarks: {$this->callNotes}");
    }

    private function flush(array $batch): void
    {
        $this->upsertBatch('appointment_reschedules', $batch, ['id'], [
            'appointment_id', 'new_appointment_id', 'confirmation_type_id', 'user_id',
            'kind', 'original_date', 'original_time', 'remarks', 'occurred_at', 'updated_at',
        ]);
    }

    /**
     * Earlier is a reschedule, the same day is a move, later is a rebook —
     * legacy's own rule. NULL when either side is unknown.
     */
    private function resolveKind(?string $originalDate, ?int $newAppointmentId): ?string
    {
        if ($originalDate === null || $newAppointmentId === null) {
            return null;
        }

        $newDate = $this->appointmentDates[$newAppointmentId] ?? null;

        if ($newDate === null) {
            return null;
        }

        $newDate = substr((string) $newDate, 0, 10);

        return match (true) {
            $newDate === $originalDate => 'move',
            $newDate < $originalDate => 'reschedule',
            default => 'rebook',
        };
    }

    private function combine(mixed $date, mixed $time): ?string
    {
        $d = $this->parseLegacyDate(trim((string) $date));
        $t = $this->parseTime($time);

        return ($d === null || $t === null) ? null : $d.' '.$t;
    }

    private function parseTime(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $value, $m) !== 1) {
            return null;
        }

        // Legacy holds impossible clock values such as '24:31'; MySQL rejects
        // them, and which day they would belong to is a guess.
        if ((int) $m[1] > 23 || (int) $m[2] > 59 || (int) ($m[3] ?? 0) > 59) {
            return null;
        }

        return \sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
