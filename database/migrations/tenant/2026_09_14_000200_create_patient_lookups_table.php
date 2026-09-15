<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One reference table for every enumerable value in the patient module, typed
 * by `type`. Shape follows the mysmyleerp reference build: `value` is the stable
 * machine code the application matches on, `name` is the display label.
 *
 * Free text is what legacy used, and it cost:
 *   - `membership_type` had THREE encodings of "no value": NULL, '' and '0'
 *   - `language` held the typo 'ENGL' in 19 rows alongside 'ENG'
 *   - `Gender` and `membership_type` both carried the literal string '0' from
 *     an unvalidated <select> placeholder
 *
 * There is deliberately NO `legacy_value` column. Translating a legacy string
 * to one of these rows is importer logic, not reference data, and this table
 * outlives the import by years — see LegacyPatientImporter, which holds those
 * maps as constants. Most need no translation at all, because `value` already
 * IS the legacy value ('MR', 'ARA', 'PAR', 'M', 'blue').
 *
 * UNIQUE(type, value) from the start. The previous build added this table
 * without it, seeded it, then could not add the constraint cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_lookups', function (Blueprint $table) {
            $table->id();

            // title | gender | document_presented | membership_type |
            // relationship | language | religion | marital_status |
            // comm_language | patient_status | contact_type
            $table->string('type', 60);

            // The stable machine code: 'MR', 'ARA', 'PAR', 'blue'.
            $table->string('value', 60);

            // The display label.
            $table->string('name');

            // Replaces legacy's trick of numbering inside the label itself
            // ("01- Thiqa Ins.Co.") purely to control display order.
            $table->unsignedInteger('sort_order')->default(0);

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'value']);
            $table->index(['type', 'active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_lookups');
    }
};
