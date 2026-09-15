<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A patient's insurance cover, one row per policy.
 *
 * Legacy kept ONE cover block inline on patient_registration across ten columns,
 * and its `patient_secondary_insurance` table was EMPTY — so secondary cover was
 * impossible and a payer change simply overwrote the previous card, expiry and
 * plan. There was no coverage history at all.
 *
 * THE OVERLOADED COLUMNS. `package_name` and `ins_pol_num` mean different things
 * depending on the payer, which is verified in the data (package_name is filled
 * only for companies 1 and 4):
 *
 *   Insurance_Company = 1 (Thiqa)    package_name is a package TIER as text,
 *                                    "Thiqa 1" / "Thiqa 2", with dirty variants
 *                                    " Thiqa 1", "Thiqa1", "thiqa 2", "Thiqa  2"
 *                                    -> package_tier_id
 *   Insurance_Company = 4 (NextCare) package_name is an insurance_sub id
 *                                    (24 = Orient PJSC) -> insurance_sub_payer_id
 *   Daman / ADNIC (a few rows)       ins_pol_num is an insurance_sub id
 *   ins_pol_num = '0' (5,323 rows)   a placeholder, not a value -> NULL
 *
 * They are split into two properly typed foreign keys here, and the 6 rows where
 * package_name and ins_pol_num disagree go to the review queue rather than being
 * silently resolved one way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_insurance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            // Real foreign keys, not bare integers with a comment. Legacy stored
            // this as a varchar and let 'AXA' (1 row) and '0' (14) through.
            $table->foreignId('insurance_company_id')->constrained('insurance_companies')->restrictOnDelete();
            $table->foreignId('insurance_plan_id')->nullable()->constrained('insurance_plans')->nullOnDelete();

            // The actual payer behind an aggregator such as NextCare.
            $table->foreignId('insurance_sub_payer_id')->nullable()
                ->constrained('insurance_sub_payers')->nullOnDelete();

            // Thiqa 1 / Thiqa 2, and any future payer tier.
            $table->foreignId('package_tier_id')->nullable()
                ->constrained('patient_lookups')->nullOnDelete();

            // PHI — `encrypted` casts on the model.
            $table->string('card_number', 100)->nullable();
            $table->string('policy_number', 100)->nullable();
            $table->string('reimbursement_card_name')->nullable();

            $table->date('effective_from')->nullable();
            $table->date('expiry_date')->nullable();
            // Legacy Insurance_Card_Expiry holds 0001-01-01 in 41 rows as
            // "unknown" — a flag, never a fake date.
            $table->boolean('expiry_unknown')->default(false);

            // The plan's cover rate AS AT binding, so that later editing the
            // plan cannot retroactively change what a historic claim was owed.
            $table->decimal('coverage_percent', 5, 4)->nullable();

            $table->boolean('is_primary')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            // See patient_identity_documents for why this exists.
            $table->string('legacy_ref', 191)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('legacy_ref');
            $table->index(['patient_id', 'is_primary']);
            $table->index(['patient_id', 'is_active']);
            $table->index('insurance_company_id');
            $table->index('insurance_sub_payer_id');
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_insurance_policies');
    }
};
