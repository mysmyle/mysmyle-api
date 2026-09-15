<?php

namespace App\Console\Legacy;

/**
 * legacy.appointment_type_log -> appointment_types
 *
 * KEY: appointment_types.id IS the legacy Appointment_type_id, so
 * `appointmentlog.visit_appointment_type` (the clinically verified type, stored
 * as an id) resolves with no translation.
 *
 * Measured live 2026-09-15: 175 rows, 175 distinct names — so `name` can carry
 * the UNIQUE index the appointment importer needs to resolve a booked type by
 * its label. One row (id 104) has a blank name; nothing references it, and a
 * type with no name cannot be offered or matched, so it is reported and skipped
 * rather than imported under an invented label.
 *
 * ── The 115 orphan labels ────────────────────────────────────────────────────
 *
 * Legacy copied the type's LABEL into every appointment as a varchar(256).
 * Types were then renamed, so **10,878 appointments (8%) name a type that no
 * longer exists in appointment_type_log** — 115 distinct dead strings:
 *
 *   "09.M- Post surgical care, Occlusal adjustment, ..."   1,643 rows
 *   "5.M- Miscellaneous (post surgery care, TMJ ...)"        946
 *   "01- Consultation & Examination"                         871
 *   "04.0 RCT/ Emergency Pulpotomy / Pulpectomy"             552
 *   "RCT| /Pulpectomy"                                       538
 *
 * These are recreated as inactive types so their appointments can still resolve
 * a type and import. That is what the mysmyleerp reference importer does too,
 * via AppointmentType::firstOrCreate(['name' => ...]) — but it creates them
 * with auto-increment ids WHILE the same table is being seeded with preserved
 * legacy ids, so a generated id can collide with a legacy Appointment_type_id
 * that has not been imported yet.
 *
 * Here they are allocated from ORPHAN_ID_BASE, above the legacy maximum, so the
 * two id spaces cannot overlap and a re-run is stable.
 *
 * ── Duration ─────────────────────────────────────────────────────────────────
 *
 * `Duration` is a varchar: 48 of 175 rows are empty and 5 hold '0', so the
 * column is nullable here and those land as NULL rather than as a defaulted 30.
 * It also carries the same (15 * n) - 1 dropdown convention as the appointment
 * itself — there are rows holding 59, 29 and 44 — so it gets the same
 * conditional correction.
 *
 * ── The `status` letter ──────────────────────────────────────────────────────
 *
 * Legacy has ONE status column holding ten different values (z 59, N 48, Y 41,
 * i 12, h 10, and one each of '', a, b, L, X) and the booking form filters on
 * 'Z', matching the 59 'z' rows only because the collation is
 * case-insensitive. There is no legacy source for the EMR and next-visit
 * flags, so all four booleans mirror that one fact and the clinic refines them
 * afterwards. The reference importer instead writes 0 to all four, which makes
 * every imported type unbookable everywhere.
 */
class LegacyAppointmentTypeImporter extends BaseLegacyImporter
{
    /**
     * Orphan labels are numbered from here. Legacy's highest
     * Appointment_type_id is 3 digits; 900000 leaves the whole legacy range
     * free and is far below the unsigned bigint ceiling.
     */
    private const ORPHAN_ID_BASE = 900000;

    public function label(): string
    {
        return 'appointment types';
    }

    /**
     * The key a legacy label is matched on. Used by this importer AND by
     * LegacyAppointmentImporter, which is the point — if the two normalise
     * differently, every label they disagree about silently becomes a second,
     * duplicate type.
     *
     * Three differences are typographic, not clinical, and all three were
     * measured on live data 2026-09-15:
     *
     *   EN DASH (U+2013) vs HYPHEN. The clinic renamed types at some point and
     *   the dash character changed with them, so "9.1- Surgery / Implant –
     *   Simple (1-2)" and "... - Simple (1-2)" are the same procedure. 12 label
     *   pairs differ only in this.
     *
     *   TRAILING AND INTERNAL WHITESPACE. 13 distinct labels across 292
     *   appointments carry a trailing newline or a doubled space, e.g.
     *   "7.4- Crowns /Crown Delivery - Simple (1-2)\n" (162 rows) and
     *   "Implant |  Impression" (double space).
     *
     *   CASE. Legacy's collation is case-insensitive, so the application never
     *   distinguished them and neither should this.
     *
     * NOTE — SQL TRIM() would not do. MySQL's TRIM removes spaces only, not
     * newlines or tabs, so doing half of this in SQL and half in PHP is exactly
     * how the two sides drift apart.
     *
     * SAFETY, verified on live: no two of the 175 canonical
     * appointment_type_log names collide under this rule, so it cannot merge
     * two genuinely different procedures. It reduces the retired-label count
     * from 114 to 99, and those 15 labels' appointments attach to the real type
     * instead of to a recreated ghost.
     */
    public static function matchKey(?string $label): string
    {
        $value = str_replace(["\u{2013}", "\u{2014}"], '-', (string) $label);
        $value = preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    public function run(): void
    {
        $batch = [];
        $known = [];
        $now = now()->toDateTimeString();

        foreach ($this->legacy()->table('appointment_type_log')->orderBy('Appointment_type_id')->get() as $row) {
            $this->read++;

            $name = trim((string) ($row->Appointment_type ?? ''));

            if ($name === '') {
                $this->skip($row->Appointment_type_id, 'blank name — nothing references it, not imported');

                continue;
            }

            $key = self::matchKey($name);

            if (isset($known[$key])) {
                $this->skip($row->Appointment_type_id, "duplicate name, already taken by type {$known[$key]}");

                continue;
            }

            $known[$key] = (int) $row->Appointment_type_id;

            // The one fact legacy records, applied to all four flags.
            $bookable = strtoupper(trim((string) ($row->status ?? ''))) === 'Z';

            $batch[] = [
                'id' => (int) $row->Appointment_type_id,
                'name' => $name,
                'duration' => $this->correctDuration($row->Duration ?? null),
                'display_order' => (int) ($row->order_log ?? 0),
                'active_initial' => $bookable,
                'active_emr' => $bookable,
                'active_next' => $bookable,
                'active' => $bookable,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $this->written++;
        }

        // The dead labels still named by appointments.
        $orphanId = self::ORPHAN_ID_BASE;

        foreach ($this->orphanLabels($known) as $name => $rows) {
            $batch[] = [
                'id' => ++$orphanId,
                'name' => $name,
                'duration' => null,
                'display_order' => 0,
                // Not bookable: this type no longer exists. It is here so its
                // history resolves, not so it can be chosen again.
                'active_initial' => false,
                'active_emr' => false,
                'active_next' => false,
                'active' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $this->written++;
            $this->skip($orphanId, "recreated retired label used by {$rows} appointment(s): \"{$name}\"");
        }

        $this->upsertBatch('appointment_types', $batch, ['name'], [
            'duration', 'display_order', 'active_initial', 'active_emr', 'active_next',
            'active', 'updated_at',
        ]);
    }

    /**
     * Labels that appointments still use but appointment_type_log no longer has.
     *
     * Grouping happens in PHP on matchKey(), not in SQL: MySQL's TRIM removes
     * spaces only, so a GROUP BY TRIM(...) would keep the newline-bearing
     * variants apart and recreate a separate ghost type for each.
     *
     * @param  array<string, int>  $knownKeys  matchKey => type id
     * @return array<string, int>  canonical label => appointment count
     */
    private function orphanLabels(array $knownKeys): array
    {
        $rows = $this->legacy()->table('appointmentlog')
            ->selectRaw('Appointment_type AS label, COUNT(*) AS n')
            ->whereRaw('TRIM(COALESCE(Appointment_type, "")) <> ""')
            ->groupBy('Appointment_type')
            ->get();

        $orphans = [];
        $labelForKey = [];

        foreach ($rows as $row) {
            $key = self::matchKey($row->label);

            if ($key === '' || isset($knownKeys[$key])) {
                continue;
            }

            // Several legacy spellings can share one key; keep the first as the
            // stored label and add the counts together, so the recreated type
            // is one row, not one per spelling.
            $label = $labelForKey[$key] ??= trim(preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $row->label) ?? '');

            $orphans[$label] = ($orphans[$label] ?? 0) + (int) $row->n;
        }

        arsort($orphans);

        return $orphans;
    }

    /**
     * Undo the legacy (15 * n) - 1 dropdown, WITHOUT breaking the values that
     * were already stored correctly.
     *
     * The reference importer adds 1 unconditionally. Applied to this column
     * that turns the 10 rows holding 59/29/44 into 60/30/45 correctly and the
     * 84 rows holding 30/45/60/15/90 into 31/46/61/16/91.
     */
    private function correctDuration(mixed $raw): ?int
    {
        $value = trim((string) $raw);

        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        $minutes = (int) $value;

        if ($minutes <= 0) {
            return null;
        }

        return $minutes % 15 === 14 ? $minutes + 1 : $minutes;
    }
}
