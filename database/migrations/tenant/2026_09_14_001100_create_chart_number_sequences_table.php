<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chart number allocation.
 *
 * Replaces legacy getChartNew(), which ran
 *     SELECT Chart FROM patient_registration
 *     WHERE Chart NOT IN (<17 hard-coded test charts>) ORDER BY Chart DESC
 * with NO LIMIT — fetching every row in the table to read one value — then added
 * 1 in PHP. A read-then-write with no lock, against a column with no unique
 * constraint, so two concurrent registrations could be handed the same chart
 * number. (1000001 was listed twice in that blacklist.)
 *
 * Allocation here is SELECT ... FOR UPDATE inside the same transaction as the
 * patient INSERT, with UNIQUE(chart) as the backstop.
 *
 * IMPORTANT: this is created now but stays DORMANT. While legacy still creates
 * patients, legacy owns chart numbers, and two allocators would collide. It is
 * switched on at the cutover, when this app becomes the writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 50)->default('default');
            $table->unsignedBigInteger('next_value');
            $table->timestamps();

            $table->unique('scope');
        });

        // The test charts that used to live in a PHP array literal inside the
        // allocator. Held as data so they are visible, auditable and editable.
        Schema::create('reserved_chart_numbers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chart');
            $table->string('reason', 191)->nullable();
            $table->timestamps();

            $table->unique('chart');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserved_chart_numbers');
        Schema::dropIfExists('chart_number_sequences');
    }
};
