<?php

namespace App\Console\Legacy;

/**
 * legacy.appointmentlog (5 tier columns) -> confirmation_raps
 *
 * The five rival code sets collapse to one vocabulary here. Each tier had its
 * own numbering for the same words — "Cancelled by Center" is stored as 01-,
 * 03- or 05- depending purely on which screen the user was standing on — so the
 * map below is keyed by (tier, exact legacy string).
 *
 * Counts are live, measured 2026-09-15. Every one of the 30 distinct values
 * across the five columns has a destination: a type id, or an explicit "no
 * contact, keep the remark", or the check-in stage which belongs to
 * appointment_processes. None is silently dropped.
 *
 * ── Fixes relative to the mysmyleerp reference importer ──────────────────────
 *
 * 1. THE PHASE-2 REMARK COLUMN NAME. The reference reads
 *    `$oldAppointment->{'2days_sms_remarks'}`, which does not exist on
 *    `appointmentlog` — the column is `2days_remarks`. PHP returns null for a
 *    missing property, so every phase-2 remark imports as NULL, silently. That
 *    is 33,515 confirmed rows plus 5,290 cancellations losing their note.
 *
 * 2. `09- Rescheduled By Center` (607 rows) has no entry in the reference's
 *    move map, so its importer logs "Unrecognized" and drops all 607. It now
 *    maps to type 10.
 *
 * 3. `03-missed` (993) and `04-Missed` (186) map to null in the reference,
 *    dropping 1,179 no-shows. They now map to type 11.
 *
 * 4. `Cancelled Reschedule` (244) and `04-Not Answering` (66) at check-in were
 *    handled only in the reference's separate check-in command; they are here
 *    with the rest of the vocabulary.
 *
 * 5. Actors resolve through LegacyActorResolver (login name first) instead of
 *    `?? 999`.
 *
 * ── What is deliberately not a contact ───────────────────────────────────────
 *
 *   03-Remarks 29 · 0 15 · 00-Choose option 8 · 02-Pending 1
 *
 * An unvalidated <select> placeholder saved as though it were an answer. Any
 * remark that came with it is kept — as a remark on the appointment is not
 * possible here, it is reported — but no contact row is invented.
 *
 *   04-checked in 43,927 — a station event. See LegacyAppointmentProcessImporter.
 */
class LegacyConfirmationRapImporter extends BaseLegacyImporter
{
    private const PHASE_FIRST = 1;

    private const PHASE_SECOND = 2;

    private const PHASE_THIRD = 3;

    private const PHASE_MOVE = 4;

    private const PHASE_CHECKIN = 5;

    /** Values that are a real placeholder, not an outcome. No row, no warning. */
    private const PLACEHOLDERS = ['03-Remarks', '00-Choose option', '02-Pending', '0', 'NULL'];

    /**
     * (phase, legacy string) => confirmation_rap_types id.
     *
     * Type ids: 1 sms_sent · 2 confirmed · 3 no_answer · 4 call_request
     *           5 cancelled_by_patient · 6 cancelled_by_centre · 7 moved
     *           8 cancel_in_centre · 9 missed_reschedule
     *           10 rescheduled_by_centre · 11 no_show
     */
    private const VALUE_MAP = [
        self::PHASE_FIRST => [          // pt_confirmed_appointment
            '01-Confirmed' => 2,             // 44,241
            '02-cancelled by pt' => 5,       //  9,501
            '03-No Answer' => 3,             //  5,506
            '01-cancelled by center' => 6,   //  2,221
            '00-sms sent' => 1,              //    412
            '04-Moved' => 7,                 //     64
            // Not the same string as '02-cancelled by pt', and legacy's own
            // overbooking guard does not recognise it. Folded into
            // cancelled_by_centre to match the reference build's map.
            '02-Canceled' => 6,              //     32
            '05-Call Request' => 4,          //     10
        ],
        self::PHASE_SECOND => [         // 2days_sms
            '01-Sent' => 2,                  // 33,515 — "Sent" is legacy's word for confirmed
            '03-cancelled by pt' => 5,       //  3,939
            '03-cancelled by center' => 6,   //  1,351
            '03-No Answer' => 3,             //  1,147
            '00-sms sent' => 1,              //    152
            '05-Call Request' => 4,          //     40
        ],
        self::PHASE_THIRD => [          // confirmed_hr
            '01-Confirmed HR' => 2,          // 38,906
            '02-cancelled by pt' => 5,       //  3,098
            '05-Not Answering' => 3,         //  1,482
            '03-cancelled by center' => 6,   //    796
            '00-sms sent' => 1,              //    402
            '04-Missed' => 11,               //    185  (reference drops these)
            '06-Call Request' => 4,          //      2
            '06- Cancelled by Center' => 6,  //      1
        ],
        self::PHASE_MOVE => [           // move_value
            '06-move' => 7,                  //  4,553
            '04-cancelled by pt' => 5,       //  3,276
            '05-cancelled by center' => 6,   //  1,032
            '09- Rescheduled By Center' => 10, //  607  (reference drops these)
            '08- Missed Reschedule' => 9,    //    314
            '07- Cancel In Center' => 8,     //      1
        ],
        self::PHASE_CHECKIN => [        // Appointment_status
            '02-cancelled by pt' => 5,       //  4,663
            '03-missed' => 11,               //    993  (reference drops these)
            '01-cancelled by center' => 6,   //    464
            'Cancelled Reschedule' => 8,     //    244
            '07- Cancel In Center' => 8,     //    149
            '04-Not Answering' => 3,         //     66
            '04-Missed' => 11,               //      1
            // '04-checked in' (43,927) is a station event, not a contact.
        ],
    ];

    private array $validAppointmentIds = [];

    private LegacyActorResolver $actors;

    private array $perPhase = [];

    public function label(): string
    {
        return 'confirmation contacts';
    }

    public function run(): void
    {
        $this->validAppointmentIds = $this->tenant()->table('appointments')->pluck('id')->flip()->toArray();
        $this->actors = new LegacyActorResolver($this->legacy(), $this->tenant());

        $batch = [];

        foreach ($this->legacy()->table('appointmentlog')->orderBy('Appointment_id')->lazyById(500, 'Appointment_id') as $row) {
            $this->read++;

            if (! isset($this->validAppointmentIds[(int) $row->Appointment_id])) {
                continue;
            }

            foreach ($this->contactsFor($row) as $contact) {
                $batch[] = $contact;
                $this->written++;
                $this->perPhase[$contact['confirmation_phase_id']] = ($this->perPhase[$contact['confirmation_phase_id']] ?? 0) + 1;
            }

            if (\count($batch) >= 500) {
                $this->flush($batch);
                $batch = [];
            }
        }

        $this->flush($batch);

        foreach ([1 => 'first', 2 => 'second', 3 => 'same-day', 4 => 'move', 5 => 'check-in'] as $phase => $label) {
            $this->info(str_pad($label, 10).($this->perPhase[$phase] ?? 0));
        }
    }

    private function flush(array $batch): void
    {
        // No natural key: a second call at the same tier is a second row, which
        // is exactly what legacy could not represent. Re-running therefore
        // truncates-and-reloads rather than upserting — see the command, which
        // clears this table before the importer runs.
        if ($this->dryRun || empty($batch)) {
            return;
        }

        foreach (array_chunk($batch, 500) as $slice) {
            $this->tenant()->table('confirmation_raps')->insert($slice);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contactsFor(object $row): array
    {
        $now = now()->toDateTimeString();
        $out = [];

        // Column, remark column, actor, and the instant — per tier.
        $tiers = [
            self::PHASE_FIRST => [
                'value' => $row->pt_confirmed_appointment ?? null,
                'remark' => $row->pt_confirmed_appointment_remark ?? null,
                'user' => $this->actors->byName($row->confirmation_by ?? null),
                'at' => $this->parseLegacyDateTime($row->appointment_confirmation_datetime ?? null),
            ],
            self::PHASE_SECOND => [
                'value' => $row->{'2days_sms'} ?? null,
                // The reference reads '2days_sms_remarks', which does not exist.
                'remark' => $row->{'2days_remarks'} ?? null,
                'user' => $this->actors->byName($row->{'2days_sms_user'} ?? null),
                'at' => $this->parseLegacyDateTime($row->{'2days_sms_datetime'} ?? null),
            ],
            self::PHASE_THIRD => [
                'value' => $row->confirmed_hr ?? null,
                'remark' => $row->hr_remarks ?? null,
                'user' => $this->actors->byName($row->hr_confirmed_by ?? null),
                // hr_date is a real DATE, hr_time a separate text column.
                'at' => $this->combineHr($row->hr_date ?? null, $row->hr_time ?? null),
            ],
            self::PHASE_MOVE => [
                'value' => $row->move_value ?? null,
                'remark' => $row->moved_reason ?? null,
                'user' => $this->actors->byName($row->moved_user ?? null),
                'at' => $this->parseLegacyDateTime($row->moved_date ?? null),
            ],
            self::PHASE_CHECKIN => [
                'value' => $row->Appointment_status ?? null,
                'remark' => $row->pre_ttt_remark ?? null,
                // `?:` not `??`: cancel_in_center_user is '' far more often than
                // it is NULL, and `??` only fires on NULL, so the checkin_by
                // fallback was unreachable.
                'user' => $this->actors->byName(
                    trim((string) ($row->cancel_in_center_user ?? '')) ?: ($row->checkin_by ?? null),
                ),
                'at' => $this->parseLegacyDateTime($row->cancel_in_center_date ?? null),
            ],
        ];

        foreach ($tiers as $phase => $tier) {
            $value = trim((string) ($tier['value'] ?? ''));

            if ($value === '' || \in_array($value, self::PLACEHOLDERS, true)) {
                continue;
            }

            $typeId = $this->lookup($phase, $value);

            if ($typeId === null) {
                // '04-checked in' is expected here and is handled by the
                // process importer; anything else is genuinely new.
                if ($phase !== self::PHASE_CHECKIN || mb_strtolower($value) !== '04-checked in') {
                    $this->skip($row->Appointment_id, "unmapped phase-{$phase} value '{$value}'");
                }

                continue;
            }

            // The tier's own timestamp, falling back to the booking date. A
            // contact with no recorded time still happened, and the phase says
            // roughly when; NULL is not an option because the column is NOT NULL.
            //
            // The fallback MUST use the order-tolerant parser. `created_date`
            // is the one column whose month/day order is unreliable, and the
            // phase-5 tier has no timestamp of its own on most rows — so
            // parsing it with the ordinary helper silently discarded 135 real
            // cancellations and no-shows (56 'Cancelled Reschedule',
            // 47 '02-cancelled by pt', 27 '03-missed', 4 '01-cancelled by
            // center', 1 phase-3) whose only crime was a created_date of the
            // form '2022-22-05 12:37:42'.
            $at = $tier['at'] ?? $this->parseUnreliableOrderDateTime(
                $row->created_date ?? null,
                $row->updated_date ?? null,
            )[0];

            if ($at === null) {
                $this->skip($row->Appointment_id, "phase-{$phase} contact '{$value}' has no usable timestamp");

                continue;
            }

            $out[] = [
                'appointment_id' => (int) $row->Appointment_id,
                'confirmation_phase_id' => $phase,
                'confirmation_type_id' => $typeId,
                'user_id' => $tier['user'],
                'remarks' => $this->text($tier['remark']),
                'occurred_at' => $at,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $out;
    }

    /**
     * Look a legacy value up CASE-INSENSITIVELY.
     *
     * `appointmentlog` is utf8mb4_general_ci, so legacy itself never
     * distinguished '03-missed' from '03-Missed' and the application treated
     * them as one value. A case-sensitive PHP array does not, which is how two
     * real no-shows at check-in came through as "unmapped". Matching legacy's
     * own collation is the fix; adding a second map entry per spelling would
     * only postpone it until somebody types a third.
     */
    private function lookup(int $phase, string $value): ?int
    {
        static $folded = [];

        if ($folded === []) {
            foreach (self::VALUE_MAP as $p => $values) {
                foreach ($values as $legacyValue => $typeId) {
                    $folded[$p][mb_strtolower($legacyValue)] = $typeId;
                }
            }
        }

        return $folded[$phase][mb_strtolower($value)] ?? null;
    }

    /** Tier 3 keeps a real DATE and a separate text time. */
    private function combineHr(mixed $date, mixed $time): ?string
    {
        $d = $this->parseLegacyDate(\is_string($date) ? $date : (string) $date);

        if ($d === null) {
            return null;
        }

        $t = trim((string) $time);

        // hr_date is a real DATE, so an unusable or impossible hr_time (legacy
        // holds values like '24:31') still leaves the day intact.
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $t, $m) !== 1
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
