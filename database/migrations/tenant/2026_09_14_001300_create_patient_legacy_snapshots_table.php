<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE ZERO-LOSS GUARANTEE.
 *
 * Before a single cleansing rule runs, the complete original legacy row is
 * stored here verbatim as JSON — all 79 columns exactly as they were, including
 * every value the import will reject: the 01/01/1900 birth dates, the
 * 111-1111-1111111-1 Emirates IDs, the "none" email addresses, the
 * Insurance_Company = 'AXA', the literal '0' placeholders, and the five
 * display-name columns that become user foreign keys.
 *
 * Nothing is ever discarded. If a mapping decision later turns out to be wrong,
 * the original value is one query away and the field can be re-derived without
 * going back to the legacy database at all.
 *
 * One row per legacy patient_registration row, INCLUDING the 52 rows involved in
 * the 18 duplicate charts — the records that lose a merge are snapshotted too,
 * which is what makes a merge reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_legacy_snapshots', function (Blueprint $table) {
            $table->id();

            // Nullable: a duplicate row that lost its merge still gets a
            // snapshot, and it is taken BEFORE patients rows exist.
            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();

            $table->string('source_table', 100)->default('patient_registration');
            $table->unsignedBigInteger('legacy_id');
            $table->unsignedBigInteger('legacy_chart')->nullable();

            // The verbatim original row. Encrypted cast on the model — it is a
            // complete unredacted patient record, the most sensitive thing here.
            $table->json('payload');

            // SHA-256 over the canonical payload. Proves the snapshot still
            // matches the source row, and detects a re-run silently drifting.
            $table->string('payload_hash', 64);

            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();

            $table->unique(['source_table', 'legacy_id']);
            $table->index('legacy_chart');
            $table->index('patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_legacy_snapshots');
    }
};
