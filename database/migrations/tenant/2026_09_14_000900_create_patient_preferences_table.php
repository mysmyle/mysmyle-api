<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient preferences as key/value, replacing ten persona_* TEXT columns that
 * legacy added to all 10,563 patient rows in order to store 46 values in total:
 *
 *   persona_socio_economics 14, persona_payment_preference 12,
 *   persona_communication_preference 6, persona_preferred_name 4,
 *   persona_preferred_appointment_day 3, persona_preferred_appointment_time 3,
 *   persona_preferred_drink 2, persona_personality_preference 2,
 *   persona_tribe_name 1, persona_preferred_hobby 1
 *
 * That is 105,630 cells to hold 46 facts. Keyed on a lookup instead, so the
 * clinic can add a preference without a schema change — which is precisely what
 * the legacy shape could not do — and the table stays sparse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('preference_key_id')->constrained('patient_lookups')->restrictOnDelete();

            // Encrypted on the model when the key's lookup row has is_phi set —
            // preferred_name, tribe_name, hobby and drink are personal details,
            // socio_economics and payment_preference are not.
            $table->text('value')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One value per key per patient — this is also the importer's
            // idempotency key, so no legacy_ref is needed here.
            $table->unique(['patient_id', 'preference_key_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_preferences');
    }
};
