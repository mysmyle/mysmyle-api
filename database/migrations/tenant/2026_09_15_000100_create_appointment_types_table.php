<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is being booked. Shape follows the mysmyleerp reference build, which
 * imports legacy `appointment_type_log` (175 rows) with the id preserved —
 * `ImportAppointmentType` writes `id => Appointment_type_id`.
 *
 * KEY: appointment_types.id IS the legacy Appointment_type_id. Same convention
 * as patients.id (= patient_registration.id) and appointments.id below, so the
 * import needs no crosswalk table.
 *
 * The four `active_*` flags are the reference's, and they answer four separate
 * questions the legacy `status` column tried to answer with one letter:
 * offer this type when booking / in the EMR / as a next visit / at all. Legacy
 * `appointment_type_log.status` holds ten different values — measured live
 * 2026-09-15: z 59, N 48, Y 41, i 12, h 10, and one each of '', a, b, L, X —
 * and the booking form filters on 'Z', matching the 59 'z' rows only because
 * the collation is case-insensitive.
 *
 * `name` IS the label legacy copied into every appointment row as a
 * varchar(256), and it drifts: measured live, **10,878 appointments (8%) name
 * a type that no longer exists**, across 115 distinct dead strings, e.g.
 * "09.M- Post surgical care, Occlusal adjustment, ..." (1,643 rows) and
 * "01- Consultation & Examination" (871 rows). The reference importer handles
 * this with `AppointmentType::firstOrCreate(['name' => ...])`, which creates
 * the missing type rather than dropping the appointment — so those 115 strings
 * land as inactive types and nothing is lost. That is why `name` is unique and
 * why the inactive defaults matter.
 *
 * `duration` comes from legacy `appointment_type_log.Duration`, which is empty
 * on 48 of 175 rows and '0' on 5 more — hence nullable, not defaulted. It also
 * carries the same (15 * n) - 1 convention as the appointment itself (there are
 * rows holding 59, 29 and 44), so the importer corrects it on the way in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_types', function (Blueprint $table) {
            // = legacy appointment_type_log.Appointment_type_id.
            $table->id();

            // ─── Data ────────────────────────────────────────────────────────
            $table->string('name');

            // True minutes. Nullable: 53 of 175 legacy rows have no duration,
            // and defaulting it would manufacture a clinical decision.
            $table->unsignedSmallInteger('duration')->nullable();

            $table->unsignedInteger('display_order')->default(0);

            // Where this type may be offered. Four questions, four columns —
            // legacy used one ten-valued letter for all of them.
            $table->boolean('active_initial')->default(true);  // the booking diary
            $table->boolean('active_emr')->default(true);      // clinical verification
            $table->boolean('active_next')->default(true);     // next-visit booking
            $table->boolean('active')->default(true);          // exists at all

            $table->timestamps();

            // ─── Indexes ─────────────────────────────────────────────────────
            // The importer resolves a legacy appointment by this label, so it
            // must be unique or firstOrCreate() can match two rows.
            $table->unique('name');
            $table->index(['active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_types');
    }
};
