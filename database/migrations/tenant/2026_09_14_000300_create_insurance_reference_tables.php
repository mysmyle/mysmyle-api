<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payer reference data: who pays, under which plan, on behalf of whom.
 *
 * Sources — legacy `insurance_company` (12), `insurance_plan` (29),
 * `insurance_sub` (38).
 *
 * These live on the TENANT connection, not the landlord, so that
 * `patient_insurance_policies` can carry real foreign keys. The previous system
 * put them on the landlord and had to fall back to bare `unsignedBigInteger`
 * columns with a comment instead of a constraint — which is exactly how
 * `Insurance_Company = 'AXA'` and `= '0'` survived in the patient master.
 * Each clinic also contracts with its own payers, so per-tenant is the honest
 * grain. If a shared catalogue is wanted later it becomes a landlord table that
 * seeds these, not a replacement for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Shorter label used on screens where the full legal name will not fit.
            $table->string('display_name')->nullable();
            // DoH eClaims payer / receiver identifiers.
            $table->string('payer_code', 50)->nullable();
            $table->string('receiver_code', 50)->nullable();
            // Malaffi (Abu Dhabi HIE) numeric id.
            $table->unsignedInteger('malaffi_id')->nullable();
            // Hex colour the legacy UI used to tint the payer on the board.
            $table->string('colour', 20)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('active');
            $table->index('payer_code');
        });

        Schema::create('insurance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_company_id')->constrained('insurance_companies')->cascadeOnDelete();
            $table->string('name');
            // The share the payer covers, as a fraction (0.8000 = 80%).
            // decimal(5,4), not a percentage int — copay maths must not round.
            $table->decimal('plan_cover', 5, 4)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['insurance_company_id', 'active']);
        });

        /**
         * The real insurer behind an aggregator. NextCare is a Third Party
         * Administrator: the entity that actually carries the risk is Orient,
         * Arabia, MEDGULF, Al Sagr and so on, each with its own DoH payer code.
         *
         * Legacy stashed this as a bare integer inside `package_name`
         * (e.g. 24 = Orient PJSC) on the patient row.
         */
        Schema::create('insurance_sub_payers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_company_id')->constrained('insurance_companies')->cascadeOnDelete();
            // Legacy insurance_sub.ins_sub_id, e.g. "A012".
            $table->string('payer_code', 30)->nullable();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['insurance_company_id', 'payer_code']);
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_sub_payers');
        Schema::dropIfExists('insurance_plans');
        Schema::dropIfExists('insurance_companies');
    }
};
