<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Country reference data — the target of `patients.nationality_id` and of the
 * country code on every phone number.
 *
 * Legacy `countries` (249 rows) is the source, but it is not usable as-is:
 *   - `nationality` holds a COMMA LIST in 39 rows ("Emirati, Emirian, Emiri"),
 *     and patient_registration.Nationality stores that whole string verbatim.
 *   - `shafafiya_nationality_code` is dirty — Niger carries "Nigerian" and
 *     Nigeria carries "Nigerien", i.e. the two are swapped.
 *   - it has no phone code at all.
 *
 * So this table keeps ONE canonical demonym per country, and every spelling the
 * legacy data actually uses lives in `country_nationality_aliases`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('num_code')->nullable();
            $table->char('alpha_2_code', 2)->nullable();
            $table->char('alpha_3_code', 3)->nullable();
            $table->string('en_short_name');
            // The one canonical demonym shown in the UI ("Emirati", "Filipino").
            $table->string('nationality')->nullable();
            // DoH/Shafafiya submission code. Kept because claims need it, but
            // never used for matching — see the swapped Niger/Nigeria note above.
            $table->string('shafafiya_nationality_code')->nullable();
            // Digits only, no '+' ("971"). String, not int: leading zeros exist.
            $table->string('phone_code', 6)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique('alpha_2_code');
            $table->unique('alpha_3_code');
            $table->index('phone_code');
            $table->index('en_short_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
