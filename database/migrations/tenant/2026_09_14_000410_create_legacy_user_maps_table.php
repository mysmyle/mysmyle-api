<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves a legacy DISPLAY NAME to a user, once, for every module.
 *
 * Legacy never stored who did something as an id — five columns on
 * patient_registration alone (created_by, updated_by, registration_by,
 * Insurance_release_form_by, passport_by) hold a display-name string. Measured:
 * 88 distinct names, 18,718 references.
 *
 *   74 names / 17,555 refs  exact match on login.user_name -> already a users
 *                           row (LegacyUserImporter keeps users.id = login_id)
 *    1 name  /      1 ref   a numeric login_id leaked into the name column
 *   13 names /  1,162 refs  no login at all: the shared desk accounts
 *                           (Reception1, reception-1, admin, reception-2,
 *                           Vision Admin, Labmanager), two typo variants of
 *                           Dr. Tahoun, and a few one-offs (Hasan, r.cuevas ...)
 *
 * The users table is NOT altered for this, and the importer never creates a
 * users row. `users` is the authentication table; an actor that never had a
 * login does not belong in it. For those 13 names `user_id` stays NULL and the
 * row is left `unresolved` for a human. If the clinic wants "Reception desk"
 * to exist as an account, an admin creates it through the Control Panel — the
 * proper path — and links it here via reviewed_by / user_id. The display name
 * itself lives only in this table and in patient_legacy_snapshots, never in a
 * domain column.
 *
 * Why not join on login.user_name at import time: it is not unique. 255 logins
 * carry 245 names; 7 names span 2-4 logins each (staff re-issued a login), so a
 * naive join fans out. The chain is name -> staff (staff.id = employee_id) ->
 * that person's current login, and the choice is recorded here once and reused
 * by every later module (appointments, EMR, claims, lab all carry *_by names).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_user_maps', function (Blueprint $table) {
            $table->id();

            // Exactly as legacy wrote it, untrimmed — one name carries a newline.
            $table->string('legacy_name', 191);

            // Lowercased, whitespace-collapsed, punctuation-folded. The lookup key.
            $table->string('legacy_name_normalised', 191);

            // Where it was first seen: "patient_registration.created_by".
            $table->string('first_seen_in', 191)->nullable();

            // NULL only while unresolved. restrictOnDelete: a user that legacy
            // work is attributed to cannot be hard-deleted out from under it.
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();

            // The legacy ids the name resolved through, for auditing the mapping
            // itself. JSON because one name may span several login rows.
            $table->json('legacy_login_ids')->nullable();
            $table->json('legacy_staff_ids')->nullable();

            // exact_login | normalised_login | via_staff | leaked_id | manual | unresolved
            $table->string('match_method', 30);

            // More than one candidate matched and a rule (or a human) chose.
            // Known cases: Dr. Hussein / Hussien Tahoun (login 5 vs 107) and
            // Alnablsi (employee 31 vs 166).
            $table->boolean('was_ambiguous')->default(false);

            // A desk login, not a person. Never attributed to an individual.
            $table->boolean('is_shared_account')->default(false);

            // How many legacy cells carry this name — drives review priority.
            $table->unsignedInteger('occurrence_count')->default(0);

            $table->text('note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique('legacy_name_normalised');
            $table->index('user_id');
            $table->index(['match_method', 'was_ambiguous']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_user_maps');
    }
};
