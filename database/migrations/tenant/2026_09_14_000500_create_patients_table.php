<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The patient master. Source: mysmyleadmin_vdc.patient_registration, 79 columns
 * holding nine different entities. Everything repeating or optional lives in its
 * own table (identity documents, documents, consents, contacts, emergency
 * contacts, insurance, preferences, notes); what is left is here.
 *
 * KEY: patients.id IS patient_registration.id. There is deliberately no
 * `legacy_id` column — the primary key carries that meaning, the same
 * convention LegacyStaffImporter and LegacyUserImporter already follow
 * (staff.id = employee_id, users.id = login_id).
 *
 * `chart` IS UNIQUE, from the first migration. That is only possible because
 * this table starts empty: 18 legacy charts span 52 rows, so the previous system
 * — which had already imported all of them as separate patients — could not add
 * the constraint without a data-repair step first. Here the importer resolves
 * duplicates BEFORE the first insert (lowest legacy id survives, the rest go to
 * patient_merges), so the constraint holds from row one and the database, not a
 * convention, is what guarantees one patient per chart.
 *
 * The importer re-runs every five minutes and re-applies that rule, so a NEW
 * duplicate created in legacy tomorrow is merged and flagged rather than
 * breaking the sync.
 *
 * Columns deliberately NOT carried from legacy, all verified empty or derivable:
 *   patient_trn, ethnic, race, photo (0% filled — never used)
 *   country_id, state_id, city_id   (0% — an address hierarchy wired to 147,811
 *                                    city rows and never populated once)
 *   Age                             (derived from date_of_birth, and already
 *                                    disagreed with the mirror in 197 rows)
 *   country                         (Malaffi code, recomputed from the country)
 *   citizen                         (duplicate of Nationality; they disagreed in
 *                                    5,541 rows — one fact, one place)
 * Every one of them stays recoverable verbatim from patient_legacy_snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            // NOT auto-incrementing by accident: the importer assigns the legacy
            // id explicitly. Kept as a normal auto-increment PK so that patients
            // created in this app (once it becomes the writer) still work.
            $table->id();

            // ─── Identity ────────────────────────────────────────────────────
            // The business key, and the join key in 164 other legacy tables.
            // Legacy allocated it with an unlocked MAX(Chart)+1 in PHP.
            $table->unsignedBigInteger('chart');

            // first_name is NULLABLE on purpose. The previous importer wrote the
            // literal string "Unknown" whenever it could not split a name, which
            // turned a missing value into a fake one in thousands of rows.
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('nickname')->nullable();

            // 5,540 of 10,563 legacy patients have ONLY the full-name string.
            // Keep the original verbatim and flag it, rather than guessing a
            // split — Arabic and multi-part names do not survive explode(' ').
            $table->string('full_name_legacy')->nullable();
            $table->boolean('name_review_required')->default(false);

            // ─── Demographics ────────────────────────────────────────────────
            // Legacy stored DOB as four incompatible string formats and used
            // 01/01/1900 1,479 times to mean "unknown". That sentinel becomes
            // NULL + is_estimated, so the fact survives without faking a date.
            $table->date('date_of_birth')->nullable();
            $table->boolean('date_of_birth_is_estimated')->default(false);

            $table->string('email')->nullable();

            $table->foreignId('nationality_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('title_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            $table->foreignId('gender_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            $table->foreignId('marital_status_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            $table->foreignId('religion_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            // Spoken language.
            $table->foreignId('language_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            // Language the clinic writes to them in — drives ChaTTo-P messaging.
            $table->foreignId('preferred_comm_language_id')->nullable()->constrained('patient_lookups')->nullOnDelete();

            // ─── Membership ──────────────────────────────────────────────────
            $table->foreignId('membership_type_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            // Legacy is_memtype_overruled: the tier was set by hand, so the
            // automatic rules must not recompute it.
            $table->boolean('membership_overruled')->default(false);

            // ─── Lifecycle ───────────────────────────────────────────────────
            $table->foreignId('status_id')->nullable()->constrained('patient_lookups')->nullOnDelete();
            // Set on the losing record of a merge; see patient_merges.
            $table->foreignId('merged_into_patient_id')->nullable()->constrained('patients')->nullOnDelete();

            // ─── Audit ───────────────────────────────────────────────────────
            // restrictOnDelete, never nullOnDelete: removing a user must not
            // silently erase who created a patient record. Legacy held a display
            // name string here, which could not be joined and could not be
            // trusted — 7 names in `login` belong to more than one login row.
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // ─── Indexes ─────────────────────────────────────────────────────
            // The identity guarantee legacy never had. See the class comment.
            $table->unique('chart');
            $table->index('last_name');
            $table->index(['last_name', 'first_name']);
            $table->index('date_of_birth');
            $table->index('status_id');
            $table->index('merged_into_patient_id');
            $table->index('name_review_required');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
