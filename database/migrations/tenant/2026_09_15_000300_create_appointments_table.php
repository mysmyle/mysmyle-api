<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The appointment. TEN columns where legacy `appointmentlog` has 187.
 *
 * Shape follows the mysmyleerp reference build exactly: scheduling only. One
 * legacy row is simultaneously a booked slot, three confirmation ledgers, the
 * insurance/finance/RCM checks, check-in, triage, the clinical encounter,
 * checkout, discharge, a 30-column consent checklist, an invoice with its
 * balance, incident references, lab flags and a Malaffi anchor. It carries no
 * foreign keys at all.
 *
 * Everything that HAPPENS to an appointment is a row in a child table, not a
 * column here:
 *
 *   appointment_processes   check-in, triage, being seen, checkout, discharge,
 *                           completion — one row per station event
 *   confirmation_raps       every confirmation / cancellation contact
 *   appointment_reschedules the move itself
 *   appointment_tentatives  the tentative chain
 *
 * That is the whole design. Adding `checked_in_at`, `triage_started_at`,
 * `discharged_at` ... as columns here would put a repeating group back on the
 * table the reference just finished taking it off.
 *
 * KEY: appointments.id IS the legacy Appointment_id — the same convention
 * patients.id, staff.id and users.id already follow, and what lets every child
 * importer find its parent with no crosswalk table.
 *
 * ── What is repaired relative to legacy ──────────────────────────────────────
 *
 * REAL DATE AND TIME TYPES. Legacy stores `appointment_date` as varchar(150)
 * and `Appointment_time` as varchar(256), so every comparison is a STRING
 * comparison. Measured live 2026-09-15: 135,655 rows are 'Y-m-d', **98 are
 * 'd-m-Y'**, 19 are empty and 1 holds a full datetime — which is why
 * MAX(appointment_date) returns '30-12-2020'. The importer parses both formats;
 * the 20 unparseable rows are reported, not silently dated.
 *
 * DURATION IS TRUE MINUTES. The legacy Unit/s dropdown emits (15 * n) - 1, so a
 * 30-minute appointment stores 29 — a FullCalendar trick to stop a 09:00-09:29
 * block visually touching the 09:30 slot. Both conventions are in the data:
 *
 *   one short (14, 29, 44 ...)  108,226 rows
 *   exact     (15, 30, 45 ...)   26,715 rows
 *   other                           618 rows
 *   zero or negative                147 rows
 *   empty                            67 rows
 *
 * The reference importer applies a blanket `+1`, which corrects the 108,226 and
 * BREAKS the 26,715 that were already right. The fix is conditional — add 1
 * only when `raw % 15 == 14` — and it belongs in the importer, not here. This
 * column holds true minutes either way; the display trick stays in the front
 * end as an exclusive end.
 *
 * ACTORS ARE FOREIGN KEYS. Legacy `created_by` is a display name: 126 distinct
 * strings, only 43 of which resolve to an employee, and 10,280 rows blank.
 *
 * ── Columns deliberately not carried ─────────────────────────────────────────
 *
 *   appointment_progress   'Cancelled' iff the row is a chart-2 doctor block,
 *                          else 'Unconfirmed'. No information; one row contains
 *                          a clinical note that leaked into it.
 *   COVID19                dead flag
 *   Chart                  the patient is patient_id
 *   ip_address             belongs to audit_logs
 *   Next_Appointment_*     a recall intention, not this appointment
 *   billing / RCM / consents / lab / incident reports -> their own modules
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            // = legacy appointmentlog.Appointment_id.
            $table->id();

            // ─── Foreign keys ────────────────────────────────────────────────
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignId('appointment_type_id')->constrained('appointment_types')->restrictOnDelete();
            $table->foreignId('appointment_status_id')->constrained('appointment_statuses')->restrictOnDelete();
            // Who booked it. Nullable because 10,280 legacy rows have no actor
            // and 83 of the 126 names cannot be resolved — the reference
            // defaults those to user 999, which invents an actor; here the
            // honest answer is "unknown".
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('created_by');

            // ─── Data ────────────────────────────────────────────────────────
            $table->date('appointment_date');
            $table->time('appointment_time');
            // True minutes. See the class comment.
            $table->unsignedSmallInteger('treatment_duration');
            $table->text('appointment_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ─── Indexes ─────────────────────────────────────────────────────
            // The diary's own query: one date, one column per doctor.
            $table->index(['appointment_date', 'doctor_id'], 'idx_appt_diary');
            $table->index(['doctor_id', 'appointment_date'], 'idx_appt_doctor');
            $table->index(['patient_id', 'appointment_date'], 'idx_appt_patient');
            $table->index(['appointment_status_id', 'appointment_date'], 'idx_appt_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
