<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who to call about this patient, and how they are related.
 *
 * Sources — legacy contact_person (35.1%), relationship (35.0%), phone2 (33.8%).
 * The previous system imported phone2 as a patient contact number of type
 * "emergency" and left this table empty, so contact_person and relationship were
 * never imported at all — the clinic could see a number to ring but not whose it
 * was. Legacy's own column comment on phone2 says it "act as the emergency
 * contact number", so it belongs to the contact, not to the patient.
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

            $table->string('name');

            // PAR | SPO | SIB | GRD | FND
            $table->foreignId('relationship_id')->nullable()->constrained('patient_lookups')->nullOnDelete();

            $table->string('mobile_number', 50)->nullable();
            $table->string('alternate_number', 50)->nullable();
            // E.164 without the '+', same shape as patient_contact_numbers.
            $table->string('normalised_number', 20)->nullable();

            $table->boolean('is_primary')->default(true);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['patient_id', 'is_active']);
            $table->index(['patient_id', 'is_primary']);
            $table->index('normalised_number');
            $table->index('relationship_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_emergency_contacts');
    }
};
