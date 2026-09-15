<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per phone number BELONGING TO THE PATIENT. Legacy held four columns
 * with overlapping meaning; three of them are the patient's own:
 *
 *   Patient_mobile_phone1  100%   -> primary
 *   pt_whatsapp            99.9%  -> whatsapp    (drives ChaTTo-P messaging)
 *   Patient_mobile_phone2   6.7%  -> alternative
 *
 * The fourth, `phone2`, is NOT the patient's. It is the emergency contact's
 * number and lives on that contact, with their name and relationship — see
 * patient_emergency_contacts for the measurements behind that. Keeping it out
 * of here matters in practice: 484 legacy emergency numbers are identical to
 * the patient's own primary mobile and 497 to their WhatsApp, so as rows in
 * this table they would be indistinguishable from the patient's own, and an
 * outbound message meant for the patient could reach their next of kin.
 *
 * A phone number is NEVER unique and never identifies a patient: 10,506 legacy
 * patients share only ~8,700 distinct primary numbers, because families share a
 * phone. Hence no unique index on contact_number.
 *
 * The natural key is (patient_id, contact_type_id) and the importer UPSERTS on
 * it. The previous system instead deleted and re-inserted every child row on
 * each sync, which churned the auto-increment ids every five minutes and meant
 * nothing could ever safely reference this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_contact_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            // primary | whatsapp | alternative | emergency | home | work
            //
            // A lookup foreign key rather than the reference build's free-text
            // column, to match `relationship_id` on the sibling table — it would
            // be inconsistent for one small controlled vocabulary in this module
            // to be an FK and the other a string.
            $table->foreignId('contact_type_id')->constrained('patient_lookups')->restrictOnDelete();

            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();

            // The subscriber number, DIGITS ONLY — no country code (that is
            // country_id), no spaces, no punctuation. Normalising happens once
            // on the way in, so there is one canonical form and nothing to keep
            // in step. Directly indexed: this is what inbound-call and
            // inbound-message lookups match on.
            //
            // Storing an "as entered" copy alongside it would buy nothing.
            // Measured: all 10,506 legacy numbers are already digits only —
            // not one contains a space, a bracket, a dash or a letter.
            $table->string('contact_number', 20);

            $table->boolean('active')->default(true);

            $table->timestamps();

            // The natural key the importer upserts on — one number per type.
            $table->unique(['patient_id', 'contact_type_id']);
            $table->index('contact_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_contact_numbers');
    }
};
