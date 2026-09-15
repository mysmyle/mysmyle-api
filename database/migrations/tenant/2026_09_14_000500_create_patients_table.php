<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The patient master. Shape follows the mysmyleerp reference build; everything
 * repeating or optional lives in its own table (contact numbers, emergency
 * contacts, payers, documents).
 *
 * Column order follows the project standard: primary key, then every foreign
 * key, then the table's own data, then timestamps.
 *
 * KEY: patients.id IS the legacy patient_registration.id for the migrated
 * tenant. That is not a migration artefact — it is the same convention
 * LegacyStaffImporter and LegacyUserImporter already follow (staff.id =
 * employee_id, users.id = login_id), and it means the import needs no crosswalk
 * table to find a patient again.
 *
 * There are no import-only columns here and no ETL scaffolding behind it. Every
 * row the importer writes is re-findable by a key that already exists, so the
 * import leaves nothing in the schema a new clinic would inherit.
 *
 * NAMES. `full_name` is the canonical, always-present name; first/middle/last
 * are the structured parts when they are known. That is the modern, i18n-safe
 * way round — names do not decompose universally, and 5,540 of 10,506 legacy
 * patients have only the full string. The previous importer guessed a split
 * with explode(' ') and discarded the original. Keeping the full string is not
 * a legacy concession; it is how the field should have been modelled anyway.
 *
 * DUPLICATE AND TEST CHARTS. `chart` is UNIQUE, which is possible because this
 * table starts empty. 18 legacy charts span 52 rows. Measured: 16 are exact
 * double-submits (identical name, phone, date of birth and payer, consecutive
 * ids) and the other two are the reserved test charts 777777 (16 rows) and
 * 1000001 (4 rows). The importer keeps the lowest legacy id per chart and
 * reports the rest, so a test chart lands as ONE patient rather than sixteen,
 * and nothing real is lost.
 *
 * Columns deliberately NOT carried from legacy, all verified empty or derivable:
 *   patient_trn, ethnic, race, photo (0% filled — never used)
 *   country_id, state_id, city_id   (0% — an address hierarchy wired to 147,811
 *                                    city rows and never populated once)
 *   Age                             (derived from date_of_birth)
 *   country                         (Malaffi code, recomputed from the country)
 *   citizen                         (duplicate of Nationality; they disagreed in
 *                                    5,541 rows — one fact, one place)
 *   is_memtype_overruled            (99.8% of the rows carrying it simply have a
 *                                    tier; it guards an auto-derivation rule
 *                                    that does not exist yet)
 *   created_by / updated_by         (display-name strings; 13 of 88 names have
 *                                    no matching login, and who those actors are
 *                                    is an open decision — deferred, not lost)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();

            // ─── Foreign keys ────────────────────────────────────────────────
            $table->foreignId('nationality_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('title_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            $table->foreignId('gender_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            $table->foreignId('marital_status_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            $table->foreignId('religion_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            $table->foreignId('document_presented_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            // What the patient speaks.
            $table->foreignId('language_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            // What the clinic writes to them in — drives ChaTTo-P messaging.
            $table->foreignId('preferred_comm_language_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            $table->foreignId('membership_type_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('patient_lookups')->nullOnDelete();

            // ─── Data ────────────────────────────────────────────────────────
            // The business key, and the join key in 164 legacy tables.
            $table->unsignedBigInteger('chart');

            // The canonical name — always present. See the class comment.
            $table->string('full_name');
            // The structured parts, when known.
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('nickname')->nullable();

            $table->date('date_of_birth')->nullable();
            $table->string('email')->nullable();
            $table->string('city')->nullable();
            $table->string('emirates')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ─── Indexes ─────────────────────────────────────────────────────
            $table->unique('chart');
            $table->index('full_name');
            $table->index('last_name');
            $table->index(['last_name', 'first_name']);
            $table->index('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
