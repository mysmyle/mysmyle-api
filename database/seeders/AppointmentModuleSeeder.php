<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Reference data for the appointments module: statuses, process types,
 * confirmation types and confirmation phases.
 *
 * Values and ids follow the mysmyleerp reference build, because the importers
 * address these rows BY ID — ImportAppointment writes `appointment_status_id`
 * directly, ImportAppointmentCheckinProcess writes `process_type_id => 1`, and
 * the four confirmation maps write type ids 1-9. Changing an id here silently
 * re-labels imported history, so each block states the id it must keep.
 *
 * All four use upsert on the natural key, so re-running is safe.
 *
 * Legacy row counts are measured on mysmyleadmin_vdc, 2026-09-15, and are here
 * so the importer can assert it mapped the expected volume and fail loudly if
 * it did not.
 */
class AppointmentModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedStatuses();
        $this->seedProcessTypes();
        $this->seedConfirmationPhases();
        $this->seedConfirmationTypes();
    }

    /**
     * Ids 1-6, relied on by ImportAppointment's status derivation.
     */
    private function seedStatuses(): void
    {
        $this->upsert('appointment_statuses', [
            // legacy is_tentative = 1                               4,654 rows
            ['id' => 1, 'key' => 'tentative', 'label' => 'Tentative'],
            // legacy status = 'Y'                                 121,713
            ['id' => 2, 'key' => 'booked', 'label' => 'Booked'],
            // legacy resched_status = 'move'                       12,994
            ['id' => 3, 'key' => 'moved', 'label' => 'Moved'],
            // legacy resched_status = 'reschedule' | 'rebook'      22,468
            ['id' => 4, 'key' => 'rescheduled', 'label' => 'Rescheduled'],
            // legacy status = 'A'                                   1,829
            ['id' => 5, 'key' => 'archived', 'label' => 'Archived'],
            // legacy status = 'F' | 'N' | 'waiting-list'           12,217
            ['id' => 6, 'key' => 'removed', 'label' => 'Removed'],
        ]);
    }

    /**
     * Ids 1-7, relied on by the seven ImportAppointment*Process commands.
     * `display_order` is the order the stations actually run in, which is what
     * makes "what stage is this patient at" a MAX() rather than a hard-coded
     * precedence list repeated in every screen.
     */
    private function seedProcessTypes(): void
    {
        $this->upsert('appointment_process_types', [
            // legacy Appointment_status='04-checked in' + Check_in_Time  49,515
            ['id' => 1, 'key' => 'checkin', 'label' => 'Checked In', 'display_order' => 1],
            // legacy is_triage_start                                     24,595
            ['id' => 2, 'key' => 'triage_start', 'label' => 'Triage Start', 'display_order' => 2],
            // legacy is_triage_end                                       25,386
            ['id' => 3, 'key' => 'triage_end', 'label' => 'Triage End', 'display_order' => 3],
            // legacy start_date + Start_time                             47,375
            ['id' => 4, 'key' => 'beingseen', 'label' => 'Being Seen Start / Forwarded to Clinic', 'display_order' => 4],
            // legacy clinic_forwarded_datetime (two formats)             47,008
            ['id' => 5, 'key' => 'checkout', 'label' => 'Checked Out / Being Seen End', 'display_order' => 5],
            // legacy discharge                                           35,902
            ['id' => 6, 'key' => 'discharge', 'label' => 'Discharged', 'display_order' => 6],
            // legacy visit_completed_datetime                            46,469
            ['id' => 7, 'key' => 'complete', 'label' => 'Visit Completed', 'display_order' => 7],
        ]);
    }

    /**
     * Ids 1-5: WHERE a contact happened. These are the five legacy columns.
     */
    private function seedConfirmationPhases(): void
    {
        $this->upsert('confirmation_rap_phases', [
            // legacy pt_confirmed_appointment
            ['id' => 1, 'key' => 'first', 'label' => 'First / Initial Confirmation', 'display_order' => 1],
            // legacy 2days_sms
            ['id' => 2, 'key' => 'second', 'label' => 'Second / Main Confirmation', 'display_order' => 2],
            // legacy confirmed_hr
            ['id' => 3, 'key' => 'third', 'label' => 'Third / Same Day Confirmation', 'display_order' => 3],
            // legacy move_value
            ['id' => 4, 'key' => 'move_reschedule', 'label' => 'Move / Reschedule', 'display_order' => 4],
            // legacy Appointment_status
            ['id' => 5, 'key' => 'checkin', 'label' => 'Cancel In Center After Check-in', 'display_order' => 5],
        ]);
    }

    /**
     * Ids 1-9: WHAT came of the contact. Each comment lists every legacy string
     * across all five columns that maps to that id, with the live row count.
     *
     * Verified against live: with these nine rows, all 30 distinct legacy values
     * across the five columns have a destination — see the migration for the
     * four that deliberately get none.
     */
    private function seedConfirmationTypes(): void
    {
        $this->upsert('confirmation_rap_types', [
            // 00-sms sent  412 (1st) + 152 (2nd) + 402 (3rd)               966
            ['id' => 1, 'key' => 'sms_sent', 'label' => '00- SMS Sent', 'display_order' => 1],

            // 01-Confirmed 44,241 · 01-Sent 33,517 · 01-Confirmed HR 38,906
            // Three strings, one meaning. The 2nd tier says "Sent" because it
            // began as an SMS blast and the label was never revisited.  116,664
            ['id' => 2, 'key' => 'confirmed', 'label' => '01- Confirmed SMS / Call', 'display_order' => 2],

            // 03-No Answer 6,654 · 05-Not Answering 1,482 ·
            // 04-Not Answering 66. Four spellings; legacy's overbooking
            // guard recognises only two of them.                          8,202
            ['id' => 3, 'key' => 'no_answer', 'label' => '02- No Answer SMS / Call', 'display_order' => 3],

            // 05-Call Request 50 · 06-Call Request 2                         52
            ['id' => 4, 'key' => 'call_request', 'label' => '03- Call Request', 'display_order' => 4],

            // 02-cancelled by pt 17,262 · 03-cancelled by pt 3,939 ·
            // 04-cancelled by pt 3,276. The largest cancellation reason
            // in the database.                                           24,477
            ['id' => 5, 'key' => 'cancelled_by_patient', 'label' => '04- Cancelled By Patient',
                'is_cancellation' => true, 'display_order' => 5],

            // 01-cancelled by center 2,685 · 03-cancelled by center 2,147 ·
            // 05-cancelled by center 1,032 · 06- Cancelled by Center 1 ·
            // 02-Canceled 32 (see note below)                            5,897
            ['id' => 6, 'key' => 'cancelled_by_centre', 'label' => '05- Cancelled By Center',
                'is_cancellation' => true, 'display_order' => 6],

            // 06-move 4,553 · 04-Moved 64. Not a cancellation: the slot
            // changes, the intention does not.                           4,617
            ['id' => 7, 'key' => 'moved', 'label' => '06- Move', 'display_order' => 7],

            // 07- Cancel In Center 150 · Cancelled Reschedule 244. The
            // patient was physically present — operationally different
            // from a phone cancellation.                                    394
            ['id' => 8, 'key' => 'cancel_in_centre', 'label' => '07- Cancel In Center',
                'is_cancellation' => true, 'display_order' => 8],

            // 08- Missed Reschedule                                         314
            ['id' => 9, 'key' => 'missed_reschedule', 'label' => '08- Missed Reschedule',
                'is_cancellation' => true, 'display_order' => 9],

            // ── Beyond the reference build's nine ────────────────────────────
            // 09- Rescheduled By Center, 607 rows on move_value. The
            // reference's map has no entry for it, so its importer logs
            // "Unrecognized" and drops all 607. The clinic moving an
            // appointment to another day is not the same as a same-day
            // move (7) and not the same as cancelling it (6).
            ['id' => 10, 'key' => 'rescheduled_by_centre', 'label' => '09- Rescheduled By Center',
                'display_order' => 10],

            // 03-missed 993 · 04-Missed 186. The reference maps both to
            // null, dropping 1,179 no-shows. auto_missed.php also writes
            // ismissed='missed' from a seven-way column combination while
            // the diary feed computes its own definition with the check
            // commented out — two rival definitions of "missed". This is
            // the only one.                                              1,179
            ['id' => 11, 'key' => 'no_show', 'label' => '10- Missed / No Show',
                'is_cancellation' => true, 'display_order' => 11],
        ]);
    }

    /**
     * NOTE on `02-Canceled` (32 rows, 1st tier only). It is folded into
     * cancelled_by_centre (6) to match the reference importer's existing map.
     * It is NOT the same string as `02-cancelled by pt`, legacy's overbooking
     * guard does not recognise it — one reason cancelled slots read as occupied
     * — and nothing in those 32 rows records who cancelled. If the clinic wants
     * them distinguishable, they need their own key rather than a guess.
     */
    private function upsert(string $table, array $rows): void
    {
        $now = now();

        $rows = array_map(fn (array $row) => array_merge([
            'is_cancellation' => false,
            'display_order' => 0,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $row), $rows);

        // `is_cancellation` only exists on confirmation_rap_types.
        if ($table !== 'confirmation_rap_types') {
            $rows = array_map(function (array $row) {
                unset($row['is_cancellation']);

                return $row;
            }, $rows);
        }

        $update = array_values(array_diff(
            array_keys($rows[0]),
            ['id', 'key', 'created_at'],
        ));

        DB::table($table)->upsert($rows, ['key'], $update);
    }
}
