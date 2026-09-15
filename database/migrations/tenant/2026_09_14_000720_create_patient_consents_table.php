<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The consent FACT, kept separate from the scanned page that evidences it.
 * Legacy conflated the two: one column held a filename and was read as "has the
 * patient consented?", so deleting a file revoked a consent.
 *
 * Replaces:
 *   general_consent, general_consent_exp
 *   Insurance_release_form, Insurance_release_form_exp,
 *   Insurance_release_form_by, Insurance_release_form_expiration
 *
 * LANDMINE, verified in the data: `general_consent_exp` is named like an expiry
 * but actually holds the SIGNING timestamp, in a format used nowhere else in the
 * schema — "2023-Jun-24 16:47:11". It maps to signed_at, NOT expires_at. Reading
 * it as an expiry would mark every consent in the system as long expired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            // general_consent | insurance_release | privacy_notice | marketing
            $table->foreignId('consent_type_id')->constrained('patient_lookups')->restrictOnDelete();

            // granted | withdrawn | expired
            $table->string('status', 20)->default('granted');

            $table->timestamp('signed_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();

            // The scan that evidences it, if there is one. A consent can be
            // validly recorded without a file, and a file can be replaced
            // without re-consenting — which is why these are two tables.
            $table->foreignId('document_id')->nullable()
                ->constrained('patient_documents')->nullOnDelete();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            // See patient_identity_documents for why this exists.
            $table->string('legacy_ref', 191)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('legacy_ref');
            $table->index(['patient_id', 'consent_type_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_consents');
    }
};
