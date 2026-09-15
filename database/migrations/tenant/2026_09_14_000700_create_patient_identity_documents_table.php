<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per identity document a patient holds.
 *
 * Replaces, on patient_registration:
 *   EID_num + Emirates_ID_Expiry      -> type emirates_id
 *   pass_num + passport_expiration    -> type passport
 *
 * Legacy kept exactly one Emirates ID and one passport per patient, so renewing
 * a document overwrote the previous number and destroyed its expiry. Here a
 * renewal is a new row, which is what a DoH / Malaffi audit trail needs.
 *
 * ON `legacy_ref`: the importer re-runs every five minutes, so every child row
 * it creates needs a deterministic identity of its own or the table grows by a
 * copy per run. `legacy_ref` is that key — "patient_registration:10432:eid" —
 * UNIQUE, and NULL for anything this app creates itself (MySQL allows many NULLs
 * in a unique index, so app rows are unconstrained by it). Every child table in
 * this module uses the same device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_identity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            // emirates_id | passport | visa | gcc_id | birth_certificate
            $table->foreignId('document_type_id')->constrained('patient_lookups')->restrictOnDelete();

            // Emirates ID "784-YYYY-NNNNNNN-C" or a passport number. Indexed so
            // reception can find a patient by document at check-in.
            $table->string('document_number', 50)->nullable();

            $table->foreignId('issuing_country_id')->nullable()->constrained('countries')->nullOnDelete();

            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            // Legacy Emirates_ID_Expiry holds 0001-01-01 as "unknown" — a flag,
            // never a fake date.
            $table->boolean('expiry_unknown')->default(false);

            // Legacy EID numbers include 85 rows of 111-1111-1111111-1, 8 of
            // 222-2222-2222222-2, 21 truncated values and one passport number
            // typed into the EID field. They are KEPT and flagged, never
            // discarded — a value we cannot validate is still evidence.
            $table->boolean('format_valid')->default(true);

            $table->boolean('is_current')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('legacy_ref', 191)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('legacy_ref');
            // Named explicitly: the generated name exceeds MySQL's 64-char limit.
            $table->index(['patient_id', 'document_type_id', 'is_current'], 'pid_current_lookup');
            $table->index(['document_type_id', 'expiry_date']);
            $table->index('expiry_date');
            $table->index('format_valid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_identity_documents');
    }
};
