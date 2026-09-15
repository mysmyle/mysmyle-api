<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tentative (pencilled-in) queue. Shape follows the mysmyleerp reference
 * build; source is legacy `appointmentlog_list` (5,821 rows,
 * latin1_swedish_ci — a third collation again, so any string join to
 * `appointmentlog` needs an explicit CAST).
 *
 * LEGACY MODELS "TENTATIVE" TWICE AND THE TWO DISAGREE. There is
 * `appointmentlog.is_tentative = 1` (4,654 rows) and there is a row in
 * `appointmentlog_list` (5,821 rows). Promoting a tentative to a real booking
 * clears the flag but leaves the list row behind, so neither count is the
 * truth and no query reconciles them.
 *
 * Here the flag is gone entirely: an appointment is tentative when its
 * `appointment_status_id` is 1, and this table records the QUEUE — the
 * tentative, what it turned into, and where it ended up. One fact, one place.
 *
 * `aplist_status` measured live 2026-09-15, mapping to appointment_statuses:
 *
 *   0  active    2,530  -> 1 tentative  (still waiting)
 *   1  booked    2,483  -> 2 booked     (became a real appointment)
 *   2  moved       768  -> 3 moved
 *   3  removed      40  -> 6 removed
 *
 * The reference importer hard-codes `appointment_status_id => 2` for every row
 * it writes, which collapses all four of those into "booked" and loses the
 * 3,338 that are not. Here the legacy value is carried across.
 *
 * The 48-hour rule ("a tentative cannot be booked inside 48 hours") is enforced
 * in legacy only in the browser. It is business logic, not schema, and belongs
 * in the booking service — recorded here so it is not lost.
 *
 * KEY: id = legacy appointmentlog_list.aplist_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_tentatives', function (Blueprint $table) {
            // = legacy aplist_id.
            $table->id();

            // ─── Foreign keys ────────────────────────────────────────────────
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            // What it became. Nullable: 2,530 entries are still waiting and
            // have become nothing yet — the reference requires this column,
            // which is why its importer can only write the ones that resolved.
            $table->foreignId('new_appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('appointment_status_id')->constrained('appointment_statuses')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('processed_by');

            // ─── Data ────────────────────────────────────────────────────────
            $table->text('remarks')->nullable();

            $table->dateTime('occurred_at');
            $table->timestamps();

            // ─── Indexes ─────────────────────────────────────────────────────
            // One queue entry per tentative appointment; lets the importer
            // upsert instead of read-then-write.
            $table->unique('appointment_id', 'uniq_tentative_appt');
            $table->index(['appointment_status_id', 'occurred_at'], 'idx_tentative_status');
            $table->index('new_appointment_id', 'idx_tentative_new');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_tentatives');
    }
};
