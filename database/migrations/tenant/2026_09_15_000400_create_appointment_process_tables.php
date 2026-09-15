<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to the patient during the visit, as ONE ROW PER EVENT.
 *
 * This is the reference build's central idea and the reason the appointment
 * table stays at ten columns. Legacy spreads the visit across ~40 columns of
 * `appointmentlog`, most of them text, in seven repeating groups of
 * (timestamp, actor). `appointment_processes` replaces all of it with:
 *
 *   (appointment_id, process_type_id, user_id, occurred_at)
 *
 * Adding an eighth station is then a row in `appointment_process_types`, not an
 * ALTER on a 135,773-row table, and "who checked this patient in, and when" is
 * the same query as "who discharged them".
 *
 * Seeded types, ids relied on by the importers (AppointmentProcessTypeSeeder):
 *
 *   1 checkin        legacy Appointment_status='04-checked in', check_in_date +
 *                    Check_in_Time, checkin_by                   49,515 rows
 *   2 triage_start   is_triage_start, is_triage_start_by          24,595
 *   3 triage_end     is_triage_end, is_triage_end_by              25,386
 *   4 beingseen      start_date + Start_time, seen_by             47,375
 *   5 checkout       clinic_forwarded_datetime, forwarded_by      47,008
 *   6 discharge      discharge, discharge_by                      35,902
 *   7 complete       visit_completed_datetime, completed_by       46,469
 *
 * (Fill rates measured live on mysmyleadmin_vdc, 2026-09-15.)
 *
 * ── Why `occurred_at` is its own column ──────────────────────────────────────
 *
 * The reference importers put the legacy event time into `created_at` and
 * `updated_at`, because the table has no other column for it. That conflates
 * two different facts: WHEN THE EVENT HAPPENED (2022) and WHEN WE RECORDED IT
 * (the import run). For imported history those differ by years, and it makes
 * `created_at` useless for its normal purpose. One extra column fixes it and
 * changes nothing else.
 *
 * ── The two-format trap ──────────────────────────────────────────────────────
 *
 * `clinic_forwarded_datetime` is the worst column in the legacy database:
 * measured live, **24,720 rows use 'Y-m-d H:i:s' and 22,285 use the unsortable
 * '12-Jan-2022 17:32:43' — in the same `text` column**, plus 3 in neither form.
 * Any range filter on it in legacy is silently wrong. `Check_in_Time` and
 * `Start_time` hold a bare '16:04' with the date in a separate column. All of
 * it lands here as one DATETIME.
 *
 * ── The natural key ──────────────────────────────────────────────────────────
 *
 * UNIQUE(appointment_id, process_type_id): a patient is checked in once per
 * appointment. This is what the reference importers simulate with an
 * `AppointmentProcess::where(...)->exists()` check before every insert — a
 * read-then-write with no constraint behind it, which is exactly the pattern
 * that leaves duplicates when two stations act at once. Here the database
 * enforces it and the importer can UPSERT instead, which also makes re-running
 * the import idempotent for free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_process_types', function (Blueprint $table) {
            $table->id();

            // ─── Data ────────────────────────────────────────────────────────
            // checkin | triage_start | triage_end | beingseen | checkout |
            // discharge | complete
            $table->string('key', 40);
            $table->string('label');

            // The order the stations run in. Makes "what stage is this patient
            // at" a MAX(display_order) instead of a hard-coded precedence list
            // repeated in every screen.
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('active')->default(true);

            $table->timestamps();

            // ─── Indexes ─────────────────────────────────────────────────────
            $table->unique('key');
            $table->index(['active', 'display_order']);
        });

        Schema::create('appointment_processes', function (Blueprint $table) {
            $table->id();

            // ─── Foreign keys ────────────────────────────────────────────────
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('process_type_id')->constrained('appointment_process_types')->restrictOnDelete();
            // Nullable: many legacy station stamps have no resolvable actor.
            // The reference falls back to user 999, which invents one.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('processed_by');

            // ─── Data ────────────────────────────────────────────────────────
            // When the event happened — NOT when we recorded it. See above.
            $table->dateTime('occurred_at');
            $table->text('remarks')->nullable();

            $table->timestamps();

            // ─── Indexes ─────────────────────────────────────────────────────
            // One event of each kind per appointment; lets the importer upsert.
            $table->unique(['appointment_id', 'process_type_id'], 'uniq_process_appt_type');
            // Every station screen: "who is at my stage, oldest first".
            $table->index(['process_type_id', 'occurred_at'], 'idx_process_stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_processes');
        Schema::dropIfExists('appointment_process_types');
    }
};
