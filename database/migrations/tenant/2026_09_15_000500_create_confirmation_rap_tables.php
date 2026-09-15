<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every contact with the patient about an appointment, and what came of it.
 * One row per contact. Shape follows the mysmyleerp reference build.
 *
 * This is the single biggest repair in the module. Legacy recorded the same
 * small vocabulary in FIVE different columns, one per station, and each station
 * invented its own numbering for the same words. Measured live on
 * mysmyleadmin_vdc, 2026-09-15:
 *
 *   MEANING              1st conf.      2nd conf.      same-day       diary move     check-in
 *                        pt_confirmed_  2days_sms      confirmed_hr   move_value     Appointment_
 *                        appointment                                                 status
 *   ------------------   ------------   ------------   ------------   ------------   ------------
 *   confirmed            01-Confirmed   01-Sent        01-Confirmed
 *                             44,241        33,515     HR   38,906
 *   cancelled by centre  01-cancelled   03-cancelled   03-cancelled   05-cancelled   01-cancelled
 *                        by center      by center      by center      by center      by center
 *                             2,221          1,351            796          1,032            464
 *   cancelled by patient 02-cancelled   03-cancelled   02-cancelled   04-cancelled   02-cancelled
 *                        by pt          by pt          by pt          by pt          by pt
 *                             9,501          3,939          3,098          3,276          4,663
 *   no answer            03-No Answer   03-No Answer   05-Not                        04-Not
 *                             5,506          1,147      Answering                    Answering
 *                                                            1,482                         66
 *
 * "Cancelled by Center" is stored as 01-, 03- OR 05- purely according to which
 * screen the user was standing on; "Cancelled by Patient" as 02-, 03- OR 04-.
 * Nothing about the CAUSE differs — only the place. So:
 *
 *   confirmation_rap_types    WHAT happened   (9 rows)
 *   confirmation_rap_phases   WHERE it happened (5 rows)
 *   confirmation_raps         the contact itself
 *
 * "How many appointments did patients cancel" becomes one query instead of a
 * five-way UNION with a different code list per branch.
 *
 * Type ids are the reference build's and are relied on by the importers:
 *
 *   1 sms_sent              00-sms sent                                  966 rows
 *   2 confirmed             01-Confirmed / 01-Sent / 01-Confirmed HR  116,664
 *   3 no_answer             03-No Answer / 05-Not Answering /
 *                           04-Not Answering                            8,202
 *   4 call_request          05-Call Request / 06-Call Request              52
 *   5 cancelled_by_patient  02-/03-/04-cancelled by pt                 24,477
 *   6 cancelled_by_centre   01-/03-/05-cancelled by center,
 *                           06- Cancelled by Center, 02-Canceled        5,897
 *   7 moved                 06-move / 04-Moved                          4,617
 *   8 cancel_in_centre      07- Cancel In Center, Cancelled Reschedule    394
 *   9 missed_reschedule     08- Missed Reschedule                         314
 *
 * Phase ids (ConfirmationRapPhaseSeeder):
 *   1 first  2 second  3 third (same-day)  4 move_reschedule  5 checkin
 *
 * ── Three things changed from the reference, all small ───────────────────────
 *
 * 1. `key` IS A MACHINE CODE, `label` IS THE DISPLAY STRING. The reference's
 *    `confirmation_rap_types` stores the display label IN `key`
 *    ('00- SMS Sent'), so renumbering a label breaks every importer map that
 *    matches on it — which is precisely the failure that produced the five
 *    rival code sets above. `confirmation_rap_phases` in the same reference
 *    already splits key/label; this makes the two consistent.
 *
 * 2. `occurred_at` IS ITS OWN COLUMN. The reference puts the legacy contact
 *    time into `created_at`, conflating when the call happened with when the
 *    import ran.
 *
 * 3. NO UNIQUE ON (appointment_id, phase_id). Calling a patient twice at the
 *    same tier is a second row, not an overwrite — legacy could not represent
 *    that at all, because each tier had exactly one set of columns.
 *
 * ── Values that get no row here, on purpose ──────────────────────────────────
 *
 *   03-Remarks 29 · 0 15 · 00-Choose option 8 · 02-Pending 1
 *
 * They are an unvalidated <select> placeholder saved as though it were an
 * answer. The importer keeps any remark that came with them and records no
 * contact. `04-checked in` (43,927) is not a contact either — it is a station
 * event and belongs in appointment_processes.
 *
 * Verified: with the maps above, all 30 distinct legacy values across the five
 * columns have a destination. None is dropped — including
 * `09- Rescheduled By Center` (607 rows), which the reference's own map omits
 * and therefore silently discards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confirmation_rap_types', function (Blueprint $table) {
            $table->id();

            // ─── Data ────────────────────────────────────────────────────────
            // sms_sent | confirmed | no_answer | call_request |
            // cancelled_by_patient | cancelled_by_centre | moved |
            // cancel_in_centre | missed_reschedule
            $table->string('key', 40);
            // '00- SMS Sent', '01- Confirmed SMS / Call' — free to renumber.
            $table->string('label');

            // Does a contact of this kind end the appointment? Legacy answered
            // this by comparing strings in auto_com.php, in several places,
            // inconsistently.
            $table->boolean('is_cancellation')->default(false);

            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('active')->default(true);

            $table->timestamps();

            // ─── Indexes ─────────────────────────────────────────────────────
            $table->unique('key');
            $table->index(['active', 'display_order']);
        });

        Schema::create('confirmation_rap_phases', function (Blueprint $table) {
            $table->id();

            // ─── Data ────────────────────────────────────────────────────────
            // first | second | third | move_reschedule | checkin
            $table->string('key', 40);
            $table->string('label');

            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('active')->default(true);

            $table->timestamps();

            // ─── Indexes ─────────────────────────────────────────────────────
            $table->unique('key');
            $table->index(['active', 'display_order']);
        });

        Schema::create('confirmation_raps', function (Blueprint $table) {
            $table->id();

            // ─── Foreign keys ────────────────────────────────────────────────
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('confirmation_phase_id')->constrained('confirmation_rap_phases')->restrictOnDelete();
            $table->foreignId('confirmation_type_id')->constrained('confirmation_rap_types')->restrictOnDelete();
            // Nullable: the WhatsApp crons have no user, and many legacy actor
            // names do not resolve. The reference defaults those to 999.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('processed_by');

            // ─── Data ────────────────────────────────────────────────────────
            // Per-tier legacy sources:
            //   1st  pt_confirmed_appointment_remark / appointment_confirmation_datetime
            //   2nd  2days_remarks                   / 2days_sms_datetime
            //   3rd  hr_remarks                      / hr_date + hr_time
            //   move moved_reason                    / moved_date
            //   chk  pre_ttt_remark                  / cancel_in_center_date
            //
            // NOTE the 2nd-tier remark column is `2days_remarks`. The reference
            // importer reads `2days_sms_remarks`, which does not exist on the
            // legacy table, so every phase-2 remark imports as null.
            $table->text('remarks')->nullable();

            // When the contact happened — not when we recorded it.
            $table->dateTime('occurred_at');

            $table->timestamps();

            // ─── Indexes ─────────────────────────────────────────────────────
            $table->index(['appointment_id', 'confirmation_phase_id'], 'idx_rap_appt_phase');
            $table->index(['confirmation_type_id', 'occurred_at'], 'idx_rap_type');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmation_raps');
        Schema::dropIfExists('confirmation_rap_phases');
        Schema::dropIfExists('confirmation_rap_types');
    }
};
