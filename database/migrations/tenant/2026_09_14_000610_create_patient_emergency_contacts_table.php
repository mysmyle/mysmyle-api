<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who to call about this patient, and how they are related — name, relationship
 * and number together, because they are one entity.
 *
 * Sources: legacy contact_person, relationship and phone2.
 *
 * WHY THE NUMBER LIVES HERE and not as a patient_contact_numbers row of type
 * `emergency`, which is how legacy and the earlier builds arranged it. Measured
 * on 10,506 legacy patients:
 *
 *   contact_person filled                3,678
 *   phone2 filled                        3,540
 *   BOTH filled                          3,540
 *   name WITHOUT a number                  138
 *   number WITHOUT a name                    0   <-- never, not once
 *
 * The number is functionally dependent on the CONTACT, never on the patient:
 * there is no such thing in this data as an emergency number with nobody
 * attached to it. Splitting the two put an attribute in a different table from
 * its entity with no foreign key to reconnect them, so nothing could say which
 * number belonged to which contact once a patient had more than one.
 *
 * And the 138 contacts with a name but no number cannot be represented in a
 * table of phone numbers at all — they would be rows in `patient_contact_numbers`
 * with a NULL contact_number, which is not a phone number.
 *
 * A further reason to keep them apart: 484 legacy emergency numbers are
 * identical to the patient's own primary mobile and 497 to their WhatsApp. As
 * rows in patient_contact_numbers those are indistinguishable from the
 * patient's own numbers, and an outbound message to "the patient" could go to
 * their next of kin.
 *
 * `relationship` was free text holding six coded values that the registration
 * page rendered from a PHP array literal. It becomes a lookup foreign key, so
 * the list is editable without a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            // PAR | SPO | SIB | GRD | FND
            $table->foreignId('relationship_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();

            $table->string('name');
            // Digits only, no country code — same shape and same rule as
            // patient_contact_numbers.contact_number. Nullable: 138 legacy
            // contacts are a name and a relationship with no number recorded,
            // and that is still useful information.
            $table->string('mobile_number', 20)->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['patient_id', 'active']);
            $table->index('mobile_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_emergency_contacts');
    }
};
