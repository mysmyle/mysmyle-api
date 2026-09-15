<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient-LEVEL files — the ones that belong to the person, not to a visit.
 *
 * Replaces, one row per FILE:
 *   Emirates_ID — legacy stored BOTH scans comma-separated in ONE column, with
 *                 the expiry date buried in the filename:
 *                 "eid35049_2028-12-15_front.JPG,eid35049_2028-12-15_back.JPG"
 *                 2,258 rows hold a pair, 8 hold a single. A repeating group in
 *                 one cell becomes rows, and the date comes out of the filename
 *                 and into a real DATE column.
 *   passport + passport_date + passport_time + passport_by
 *   registration_form + registration_date + registration_time +
 *     registration_by + registration_expiration
 *   general_consent and Insurance_release_form — the FILE half only; the consent
 *     fact itself is a separate record in patient_consents.
 *
 * Appointment-level documents (the per-visit document centre) are a different
 * table and a different grain; they arrive with the Appointments module. A
 * patient's Emirates ID scan is not attached to any one visit, which is exactly
 * why it needs this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            // Links a scan to the identity record it evidences.
            $table->foreignId('patient_identity_document_id')->nullable()
                ->constrained('patient_identity_documents')->nullOnDelete();

            // eid_front | eid_back | passport | registration_form |
            // general_consent | insurance_release_form | photo | other
            $table->string('document_key', 60);

            $table->string('file_path', 512);
            $table->string('file_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->date('expiry_date')->nullable();

            // Legacy split upload date and time across two varchar columns, and
            // two live writers disagreed on the shape: one check-in backend
            // writes a date only, the other a date AND time into the same
            // column. One real timestamp here; the importer accepts both.
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // uploaded | archived | replaced
            $table->string('status', 20)->default('uploaded');
            $table->boolean('is_current')->default(true);

            // See patient_identity_documents for why this exists.
            $table->string('legacy_ref', 191)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('legacy_ref');
            $table->index(['patient_id', 'document_key', 'is_current']);
            $table->index('patient_identity_document_id');
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_documents');
    }
};
