<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lifecycle state of an appointment. Six rows, seeded by
 * AppointmentStatusSeeder, following the mysmyleerp reference build:
 *
 *   1 tentative   2 booked   3 moved   4 rescheduled   5 archived   6 removed
 *
 * Those ids are relied on by the importer, exactly as in the reference —
 * `ImportAppointment` derives the status from legacy `resched_status` and
 * `status` and writes the id directly. Measured live 2026-09-15, that maps:
 *
 *   resched_status 'rebook' 7,709 / 'reschedule' 14,759  -> 4 rescheduled
 *   resched_status 'move'          12,994                -> 3 moved
 *   status 'Y'                    121,713                -> 2 booked
 *   status 'A'                      1,829                -> 5 archived
 *   status 'F' 1,575 / 'N' 4,448 / 'waiting-list' 6,194  -> 6 removed
 *   is_tentative = 1                4,654                -> 1 tentative (wins)
 *
 * `key` is the machine code the application matches on and `label` is what the
 * screen shows — the same split `patient_lookups` already uses (`value` /
 * `name`) and that `confirmation_rap_phases` uses in the reference. The
 * reference's own `appointment_statuses` has only a nullable `key` and no
 * label, which meant the display string had to be rebuilt in the front end;
 * that is the one thing changed here.
 *
 * UNIQUE(key) from the start. The reference added this table without it.
 *
 * NOTE — cancellation is deliberately NOT a status. Where and why an
 * appointment was cancelled lives in `confirmation_raps`, because legacy
 * records it per station with a different code set each time and a single
 * status column cannot carry that. "Is this appointment cancelled?" is
 * answered by a confirmation_raps row whose type is a cancellation, which is
 * how the reference models it too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_statuses', function (Blueprint $table) {
            $table->id();

            // ─── Data ────────────────────────────────────────────────────────
            // tentative | booked | moved | rescheduled | archived | removed
            $table->string('key', 40);
            $table->string('label');

            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('active')->default(true);

            $table->timestamps();

            // ─── Indexes ─────────────────────────────────────────────────────
            $table->unique('key');
            $table->index(['active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_statuses');
    }
};
