<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per phone number. Legacy held four columns with overlapping meaning:
 *   Patient_mobile_phone1  100%  primary mobile
 *   pt_whatsapp            99.9% WhatsApp number, drives ChaTTo-P messaging
 *   Patient_mobile_phone2   6.7% secondary mobile
 *   phone2                 33.8% NOT a patient number — the column comment in
 *                                legacy says it "act as the emergency contact
 *                                number", so it migrates to
 *                                patient_emergency_contacts, not here.
 *
 * A phone number is NEVER unique and never a patient key: 10,563 legacy patients
 * share only 8,763 distinct primary numbers, because families share a phone.
 *
 * The natural key is (patient_id, contact_type_id) and the importer UPSERTS on
 * it. The previous system instead deleted and re-inserted every child row on
 * each sync, which churned the auto-increment ids every five minutes and meant
 * nothing could ever safely foreign-key onto this table. Upserting on the
 * natural key keeps ids stable, so referencing a number is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_contact_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            // mobile | mobile_2 | whatsapp | home | work
            $table->foreignId('contact_type_id')->constrained('patient_lookups')->restrictOnDelete();

            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();

            // As entered, for display.
            $table->string('contact_number', 50);

            // E.164 without the '+' ("971501234567"). Indexed: this is what
            // inbound-call and inbound-message lookups match on.
            $table->string('normalised_number', 20)->nullable();

            $table->boolean('is_primary')->default(false);
            $table->boolean('is_whatsapp')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('verified_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The natural key the importer upserts on — one number per type.
            $table->unique(['patient_id', 'contact_type_id']);
            $table->index(['patient_id', 'is_primary']);
            $table->index(['patient_id', 'is_whatsapp']);
            $table->index('normalised_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_contact_numbers');
    }
};
