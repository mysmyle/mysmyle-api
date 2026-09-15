<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A patient's cover: which payer, under which policy, at which tier, through
 * which administrator. One row per cover, so a patient can hold primary and
 * secondary cover and a renewal is a new row rather than an overwrite.
 *
 * Legacy kept ONE cover block inline on patient_registration across ten columns
 * and left its `patient_secondary_insurance` table empty, so secondary cover was
 * impossible and changing payer destroyed the previous card, expiry and plan.
 * There was no coverage history at all.
 *
 * Legacy column -> here:
 *   Insurance_Company                  payer_id
 *   insurance_plan                     payer_policy_id
 *   package_name (Thiqa)               payer_package_id
 *   package_name / ins_pol_num (TPA)   payer_third_party_id
 *   Insurance_Card_Number              insurance_number
 *   Insurance_Card_Expiry              expiry_date  (+ expiry_unknown)
 *   Reimbursment_Insurance_Card_Name   reimbursement_card_name  (1 row)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_payers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            // A real foreign key. Legacy stored this as a varchar and let the
            // literal 'AXA' (1 row) and '0' (14 rows) through into the patient
            // master, where nothing could ever resolve them.
            $table->foreignId('payer_id')->constrained('payers')->restrictOnDelete();

            $table->foreignId('payer_policy_id')->nullable()->constrained('payer_policies')->nullOnDelete();
            $table->foreignId('payer_package_id')->nullable()->constrained('payer_packages')->nullOnDelete();
            $table->foreignId('payer_third_party_id')->nullable()->constrained('payer_third_parties')->nullOnDelete();

            $table->string('insurance_number', 100)->nullable();
            $table->string('reimbursement_card_name')->nullable();

            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            // Legacy Insurance_Card_Expiry holds 0001-01-01 in 41 rows to mean
            // "unknown". A flag, never a fake date.
            $table->boolean('expiry_unknown')->default(false);

            // The policy cover rate AS AT binding. Editing the policy later must
            // not retroactively change what a historic claim was owed.
            $table->decimal('coverage_percent', 5, 4)->nullable();

            // Primary vs secondary cover — the thing legacy could not express.
            $table->boolean('is_primary')->default(true);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['patient_id', 'is_primary']);
            $table->index(['patient_id', 'active']);
            $table->index('payer_id');
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_payers');
    }
};
