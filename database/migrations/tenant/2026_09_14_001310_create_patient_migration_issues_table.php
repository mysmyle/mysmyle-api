<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The human review queue. Every value the importer cannot map with confidence
 * lands here WITH its original text, instead of being silently dropped or
 * silently guessed. This is the other half of the zero-loss guarantee: for every
 * legacy column, mapped + quarantined must equal the original fill count.
 *
 * Expected volume, from the audit of the legacy table:
 *   name_unsplittable       5,540  only a full-name string, no first/last
 *   citizen_conflict        5,541  citizen disagrees with Nationality
 *   dob_ambiguous           1,917  d/m/Y where both parts are <= 12
 *   dob_sentinel            1,479  01/01/1900
 *   nationality_variant     2,067  "Emirati, Emirian, Emiri" vs "Emirati"
 *   nationality_junk          204  the literal string '0'
 *   nationality_unmapped        7  Swede, Dutchman, Pole, Spaniard ...
 *   nationality_ambiguous     218  "American" and "Dominican" — two countries
 *                                  claim the spelling; seeded choice recorded
 *   gender_junk               306  the literal string '0'
 *   membership_junk           512  the literal string '0'
 *   eid_invalid_format        160  incl. 85 x 111-1111-1111111-1
 *   email_invalid             124  incl. 92 x "none"
 *   duplicate_chart            52  rows across 18 charts
 *   insurance_expiry_sentinel  41  0001-01-01
 *   insurance_company_junk     15  'AXA' x1 and '0' x14
 *   package_conflict            6  package_name disagrees with ins_pol_num
 *   dob_invalid                 4  e.g. 11111-11-11
 *   test_chart                 20+ reserved test charts imported and flagged
 *
 * `severity` drives triage, not correctness: `high` means the record should not
 * be treated as clean until a human has looked at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_migration_issues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->unsignedBigInteger('legacy_chart')->nullable();

            $table->string('issue_type', 60);
            $table->string('field_name', 100)->nullable();

            // Deterministic identity for this issue:
            // "patient_registration:10432:dob_sentinel:Date_of_birth".
            // The importer re-runs every five minutes, so without this the queue
            // would grow by a duplicate copy of every issue on every run.
            $table->string('source_key', 191);

            // EXACTLY what legacy held — never normalised, never truncated.
            $table->text('raw_value')->nullable();
            // What the importer would write, or did write.
            $table->text('proposed_value')->nullable();
            // What a human decided instead.
            $table->text('resolved_value')->nullable();

            // low | medium | high
            $table->string('severity', 10)->default('medium');
            // open | resolved | accepted | wont_fix
            $table->string('status', 20)->default('open');

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            // The importer re-runs every five minutes: one open issue per
            // (legacy row, issue type, field), not a new one per run.
            $table->unique('source_key');
            $table->index(['issue_type', 'status']);
            $table->index(['status', 'severity']);
            $table->index('patient_id');
            $table->index('legacy_chart');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_migration_issues');
    }
};
