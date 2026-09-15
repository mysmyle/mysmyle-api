<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reschedule: the old appointment, the new one, and why it moved.
 * Shape follows the mysmyleerp reference build; source is legacy
 * `appointmentlog_resched_details` (42,408 rows, utf8mb3_unicode_ci while
 * `appointmentlog` is utf8mb4).
 *
 * A reschedule in legacy NEVER edits a row. It inserts a new appointment and
 * points the old one at it through `new_appid`, so one patient intention is a
 * linked list. Measured live 2026-09-15:
 *
 *   new_appid holds a real successor   52,763 rows
 *   new_appid = 0                      57,806 rows   <- also means "no successor"
 *   new_appid IS NULL                  25,204 rows   <- so does this
 *
 * Two encodings of "nothing", which is why `WHERE new_appid IS NULL` — the
 * obvious way to ask "is this the current version?" — silently misses 57,806
 * rows. Here there is one encoding: NULL.
 *
 * The KIND of move is derived by legacy from comparing the two dates rather
 * than being chosen: earlier is 'reschedule', same day is 'move', later is
 * 'rebook'. Measured on appointmentlog.resched_status: reschedule 14,759,
 * move 12,994, rebook 7,709, null 100,306.
 *
 * ── `reschedule_checkbox` MIXES CODES AND CALL NOTES ─────────────────────────
 *
 * This is why `confirmation_type_id` is nullable and `remarks` is not just a
 * copy of `reschedule_reason`. Measured live:
 *
 *   05- Move                                              10,089   a code
 *   04- Cancelled by PT                                    9,083   a code
 *   05- Reschedule                                         7,359   a code
 *   Currently speaking with the patient                    5,729   a CALL NOTE
 *   Spoke to the patient (this night, this morning, ...)   2,768   a CALL NOTE
 *   03- Cancelled by Center                                2,082   a code
 *   Cancelled by PT                                        1,668   same code, unnumbered
 *   00- Book                                               1,493   a code
 *   09- Rescheduled By Center                                788
 *   06- Move                                                 722   'Move' renumbered
 *   08- Missed Reschedule                                    340
 *   Cancelled by Center                                      165   unnumbered again
 *   07- Cancel In Center                                      83
 *   Reschedule / Move / Cancelled by Patient              18/14/1
 *
 * **8,497 rows — a fifth of the table — carry a call note where a code is
 * expected.** The reference importer maps this column to a type id and skips
 * anything unmatched, so all 8,497 are lost. Here an unmatched value keeps its
 * text in `remarks` and leaves `confirmation_type_id` null, which is the honest
 * record: somebody rescheduled and left a note instead of picking a reason.
 *
 * `00- Book` (1,493) is mapped by the reference to type 7 (moved). It is not a
 * move — it is the initial booking of a slot that had none. It gets no type
 * here for the same reason the placeholders do not.
 *
 * KEY: id = legacy appointmentlog_resched_details.reschedule_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_reschedules', function (Blueprint $table) {
            // = legacy reschedule_id.
            $table->id();

            // ─── Foreign keys ────────────────────────────────────────────────
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            // The replacement. Nullable: a cancellation with no rebooking is a
            // real outcome, and legacy's `resched_newid` is a `text` column that
            // is frequently empty.
            $table->foreignId('new_appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            // Nullable — see the class comment on call notes.
            $table->foreignId('confirmation_type_id')->nullable()
                ->constrained('confirmation_rap_types')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('processed_by');

            // ─── Data ────────────────────────────────────────────────────────
            // move | reschedule | rebook — derived from the date comparison,
            // stored rather than recomputed on every render.
            $table->enum('kind', ['move', 'reschedule', 'rebook'])->nullable();

            // The original slot as it was shown at the time. Legacy's
            // `orig_date` / `orig_time` sometimes disagree with the row they
            // point at, and which one the user saw is itself a fact.
            $table->date('original_date')->nullable();
            $table->time('original_time')->nullable();

            $table->text('remarks')->nullable();

            $table->dateTime('occurred_at');
            $table->timestamps();

            // ─── Indexes ─────────────────────────────────────────────────────
            $table->index(['appointment_id', 'occurred_at'], 'idx_resched_appt');
            $table->index('new_appointment_id', 'idx_resched_new');
            $table->index(['kind', 'occurred_at'], 'idx_resched_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_reschedules');
    }
};
