<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One reference table for every enumerable value in the patient module, typed
 * by `type`. Free text is what legacy used, and it cost:
 *   - `membership_type` had THREE encodings of "no value": NULL, '' and '0'
 *   - `language` held the typo 'ENGL' in 19 rows alongside 'ENG'
 *   - `Gender`, `Nationality`, `membership_type` and `Insurance_Company` all
 *     carried the literal string '0' from an unvalidated <select> placeholder
 *
 * `code` is the stable machine value the application matches on, so no logic
 * ever depends on a display label. `legacy_value` is exactly what legacy wrote,
 * so the importer resolves deterministically and 'ENGL' and 'ENG' can both land
 * on the right row without pattern matching.
 *
 * UNIQUE(type, code) is applied from the start. The previous system added this
 * table without `code`, seeded it, and then could not add the constraint
 * cleanly — NULL never collides in a unique index, so a second seeder silently
 * doubled every lookup instead of updating it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_lookups', function (Blueprint $table) {
            $table->id();

            // gender | language | comm_language | marital_status | religion |
            // relationship | membership_type | patient_status | contact_type |
            // identity_document_type | consent_type | note_type | package_tier |
            // preference_key | title
            $table->string('type', 60);

            // Stable machine value: 'ARA', 'PAR', 'emirates_id'.
            $table->string('code', 60);

            // Display label.
            $table->string('name');

            // The exact string legacy wrote, for deterministic import. Nullable:
            // values the clinic adds later have no legacy counterpart.
            $table->string('legacy_value', 191)->nullable();

            // Drives whether patient_preferences.value is encrypted on the model.
            $table->boolean('is_phi')->default(false);

            // Replaces legacy's trick of numbering inside the label
            // ("01- Thiqa Ins.Co.") purely to control display order.
            $table->unsignedInteger('sort_order')->default(0);

            // Seeded by the system and relied on by code — the UI must not let
            // it be deleted or recoded.
            $table->boolean('is_system')->default(false);

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'code']);
            $table->index(['type', 'active', 'sort_order']);
            $table->index(['type', 'legacy_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_lookups');
    }
};
