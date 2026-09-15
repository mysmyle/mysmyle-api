<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who pays, and under what arrangement. Modelled on the payer design already
 * proven in the mysmyleerp reference build, because it is the shape that
 * untangles legacy correctly.
 *
 * Legacy crammed four different facts into two varchar columns on the patient
 * row, and what they mean depends on the payer (verified: package_name is
 * filled only for companies 1 and 4):
 *
 *   Insurance_Company = 1 (Thiqa)     package_name holds a PACKAGE TIER as text
 *                                     ("Thiqa 1", "Thiqa 2", plus the dirty
 *                                     spellings " Thiqa 1", "Thiqa1", "thiqa 2",
 *                                     "Thiqa  2")            -> payer_packages
 *   Insurance_Company = 4 (NextCare)  package_name holds an insurance_sub id
 *                                     (24 = Orient PJSC)     -> payer_third_parties
 *   Daman / ADNIC (a few rows)        ins_pol_num holds an insurance_sub id
 *   ins_pol_num = '0' (5,323 rows)    a placeholder, not a value -> NULL
 *
 * Four tables, four facts, each a real foreign key:
 *
 *   payers               the entity that pays. `is_insurance = false` lets
 *                        self-pay be a payer too, so billing has one path.
 *   payer_policies       the plan, and the share it covers.
 *   payer_packages       the tier WITHIN a payer (Thiqa 1 / Thiqa 2). This
 *                        belongs to the payer, which is why it is here and not
 *                        a row in patient_lookups.
 *   payer_third_parties  the real insurer behind an administrator. NextCare is
 *                        a TPA; the entity carrying the risk is Orient, Arabia,
 *                        MEDGULF and so on, each with its own DoH code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Shorter label for screens where the legal name will not fit.
            $table->string('display_name')->nullable();
            // DoH eClaims identifiers.
            $table->string('payer_code', 50)->nullable();
            $table->string('receiver_code', 50)->nullable();
            // Malaffi (Abu Dhabi HIE) numeric id.
            $table->unsignedInteger('malaffi_id')->nullable();
            // False for self-pay and other non-insurance payers.
            $table->boolean('is_insurance')->default(true);
            // Hex colour legacy used to tint the payer on the board.
            $table->string('colour', 20)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('active');
            $table->index('payer_code');
        });

        Schema::create('payer_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payer_id')->constrained('payers')->cascadeOnDelete();
            $table->string('name');
            // The share the payer covers, as a fraction: 0.8000 = 80%.
            // decimal, not float — copay arithmetic must not drift.
            $table->decimal('plan_cover', 5, 4)->nullable();
            $table->unsignedTinyInteger('policy_order')->default(0);
            $table->boolean('is_insurance')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['payer_id', 'active']);
        });

        Schema::create('payer_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payer_id')->constrained('payers')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['payer_id', 'name']);
        });

        Schema::create('payer_third_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payer_id')->constrained('payers')->cascadeOnDelete();
            // Legacy insurance_sub.ins_sub_id, e.g. "A012".
            $table->string('tpa_id', 50)->nullable();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['payer_id', 'tpa_id']);
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payer_third_parties');
        Schema::dropIfExists('payer_packages');
        Schema::dropIfExists('payer_policies');
        Schema::dropIfExists('payers');
    }
};
