<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes about a patient, one row per note.
 *
 * Replaces patient_registration.patient_notes — a single TEXT column, so writing
 * a new note destroyed the previous one and no note had an author or a date.
 * Also gives the legacy `pt_notes` table (57,384 rows of patient / RAP / clinic
 * notes) a typed home when that module is migrated.
 *
 * NOTE: there is deliberately no appointment_id column yet. Some legacy notes
 * are attached to a visit, but `appointments` does not exist in this app until
 * the Appointments module lands, and a nullable integer with no foreign key
 * behind it is exactly the pattern this rebuild is replacing. The column is
 * added, constrained, by that module's own migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            // patient | rap | clinic | registration
            $table->foreignId('note_type_id')->constrained('patient_lookups')->restrictOnDelete();

            // PHI — `encrypted` cast on the model. Free-text clinical notes are
            // the most sensitive field in the module.
            $table->text('body');

            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_active')->default(true);

            // See patient_identity_documents for why this exists.
            $table->string('legacy_ref', 191)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('legacy_ref');
            $table->index(['patient_id', 'note_type_id', 'is_active']);
            $table->index(['patient_id', 'is_pinned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_notes');
    }
};
