<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every spelling of a nationality that any source has ever written, mapped to
 * one country. This is what makes nationality import EXACT instead of fuzzy.
 *
 * Why this table exists — measured on 10,418 legacy patients with a nationality:
 *   The live importer matches `countries.nationality` and `en_short_name`
 *   exactly and nothing else, so it drops 3,132 patients' nationality on the
 *   floor: legacy writes "Emirati, Emirian, Emiri" (2,067 patients),
 *   "Philippine, Filipino" (679) and "British, UK" (175), none of which equal a
 *   clean demonym.
 *
 * A repeating group ("Emirati, Emirian, Emiri" in one cell) becomes rows, which
 * is the same rule applied everywhere else in this module. Adding a spelling
 * later is an INSERT, not a code change.
 *
 * `source` records WHERE the alias came from, and doubles as the precedence
 * order when two countries claim the same spelling (15 do). Lower wins:
 *   1 nationality      — the canonical demonym, or the whole legacy comma list
 *   2 nationality_part — one element of a legacy comma list
 *   3 en_short_name    — the country name itself ("Egypt")
 *   4 shafafiya        — the DoH code; LAST because legacy has it swapped for
 *                        Niger/Nigeria and misfiled "Indian" onto the British
 *                        Indian Ocean Territory
 *   0 manual           — a human decision, beats everything
 *
 * That ordering resolves "Indian" to India (527 patients) and "Nigerian" to
 * Nigeria automatically. Genuinely ambiguous spellings — "American" (US vs US
 * Minor Outlying Islands, 201 patients) and "Dominican" (Dominica vs Dominican
 * Republic, 17) — are seeded as `manual` so the choice is recorded, not guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_nationality_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();

            // The spelling as written, trimmed. Matching is done on the
            // normalised form below, never on this.
            $table->string('alias');

            // Lowercased, whitespace-collapsed, punctuation-stripped. UNIQUE:
            // one spelling resolves to exactly one country, decided at seed time
            // by `source` precedence. That is what stops the fan-out the legacy
            // joins suffer from.
            $table->string('alias_normalised', 191);

            // manual | nationality | nationality_part | en_short_name | shafafiya
            $table->string('source', 20)->default('nationality');

            $table->timestamps();

            $table->unique('alias_normalised');
            $table->index('country_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('country_nationality_aliases');
    }
};
