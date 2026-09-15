<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duplicate resolution — a concept legacy did not have at all.
 *
 * Measured legacy state: 18 chart numbers span 52 rows. They are exact-duplicate
 * patients from double submits — identical name, identical created_date,
 * consecutive id. Because `Chart` was never unique and `Chart` is the join key in
 * 164 other legacy tables, EVERY downstream join on those patients silently
 * returns too many rows.
 *
 * Resolving them is the first data step of the migration and the precondition
 * for UNIQUE(chart): keep the lowest legacy id as the survivor, record the rest
 * here with a full pre-merge snapshot, and point them at the survivor through
 * patients.merged_into_patient_id.
 *
 * The snapshot is what makes a merge reversible. This table also gives the
 * ChaTTo-P guest-to-patient merge a home — today that mutates rows in place and
 * leaves no record of what was absorbed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surviving_patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('merged_patient_id')->constrained('patients')->cascadeOnDelete();

            // The chart number the merge frees up.
            $table->unsignedBigInteger('merged_chart')->nullable();

            // duplicate_registration | guest_merge | manual
            $table->string('reason', 60)->nullable();

            // Complete pre-merge state of the losing record, so the merge can be
            // undone. Encrypted cast on the model — it is a whole patient row.
            $table->json('merged_snapshot')->nullable();

            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at')->nullable();
            $table->timestamps();

            // A record can only be merged away once.
            $table->unique('merged_patient_id');
            $table->index('surviving_patient_id');
            $table->index('merged_chart');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_merges');
    }
};
