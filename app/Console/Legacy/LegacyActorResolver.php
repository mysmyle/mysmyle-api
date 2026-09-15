<?php

namespace App\Console\Legacy;

use Illuminate\Database\Connection;

/**
 * Turns a legacy "who did this" value into a tenant users.id.
 *
 * The appointment module needs this sixteen times over, because legacy records
 * the actor differently in almost every column. Measured live on
 * mysmyleadmin_vdc 2026-09-15, thirteen columns hold a DISPLAY NAME and three
 * hold an INTEGER id:
 *
 *   names   created_by, checkin_by, confirmation_by, 2days_sms_user,
 *           hr_confirmed_by, moved_user, seen_by, forwarded_by, discharge_by,
 *           completed_by, cancel_in_center_user, reschedule_by
 *   ids     is_triage_start_by, is_triage_end_by, discharge_by_id, aplist_by
 *
 * Getting that backwards silently misattributes every event to whoever happens
 * to own the id, which is why the two paths are separate methods.
 *
 * ── Why names resolve against `login`, not `employee_register` ───────────────
 *
 * These strings were written from the legacy PHP session, so they are the
 * LOGIN's display name, not the employee's. Resolving them the other way round
 * is the difference between a usable actor column and an empty one. Measured
 * live, matching on `employee_register.employee_name` alone:
 *
 *              rows filled   matched via employee_name only
 *   created_by     125,495                40%
 *   checkin_by      44,679                 4%
 *   seen_by         37,513                 3.6%
 *   discharge_by    35,631                 3.9%
 *
 * Matching `login.user_name` first and falling back to `employee_name`:
 *
 *              via login   via employee   unresolved
 *   created_by    83,210         42,937       15,422   (89% resolved)
 *   checkin_by    45,981            363          718   (98%)
 *   seen_by       39,324              0          137   (99%)
 *   discharge_by  33,685              0        4,241   (89%)
 *
 * ── Ambiguity is resolved deterministically, and counted ─────────────────────
 *
 * 255 logins carry only 245 distinct names: seven names belong to two-to-four
 * accounts each (one person, re-created over the years — "mohammad abdul mohsen
 * alnablsi" has four). The lowest login_id that still exists in the tenant wins,
 * so a re-run always picks the same person, and the count is reported rather
 * than hidden.
 *
 * ── Nothing is invented ──────────────────────────────────────────────────────
 *
 * An unresolvable actor returns NULL. The mysmyleerp reference importers write
 * `?? 999` instead, which attributes tens of thousands of events to a user id
 * that means nothing — 15,422 `created_by` rows alone. NULL is the honest
 * record of "legacy did not say".
 */
class LegacyActorResolver
{
    /** lower(login.user_name) => users.id */
    private array $byLoginName = [];

    /** lower(employee_register.employee_name) => users.id */
    private array $byEmployeeName = [];

    /** users.id => true */
    private array $validUserIds = [];

    /** Names that belonged to more than one login, and how many. */
    private array $ambiguous = [];

    public function __construct(Connection $legacy, Connection $tenant)
    {
        // users.id IS the legacy login_id (LegacyUserImporter), and
        // staff.id IS the legacy employee_id (LegacyStaffImporter). Both maps
        // therefore land on a users.id with no crosswalk table.
        $this->validUserIds = $tenant->table('users')->pluck('id')->flip()->toArray();

        // Lowest login id per staff member wins, matching the byLoginName rule.
        // Built with an explicit loop, NOT pluck(): with duplicate keys pluck()
        // keeps the LAST value, so ordering ascending would hand every one of
        // the 22 multi-account staff their HIGHEST — and therefore usually
        // newest or abandoned — login. It picks a real person either way, but
        // "lowest wins" should mean what it says.
        $staffToUser = [];

        foreach ($tenant->table('users')->whereNotNull('staff_id')->orderBy('id')->get(['id', 'staff_id']) as $user) {
            $staffToUser[(int) $user->staff_id] ??= (int) $user->id;
        }

        // Ascending, so the first sighting of a name is the lowest login_id.
        foreach ($legacy->table('login')->orderBy('login_id')->get(['login_id', 'user_name']) as $row) {
            $name = $this->normalise($row->user_name);

            if ($name === '' || ! isset($this->validUserIds[(int) $row->login_id])) {
                continue;
            }

            if (isset($this->byLoginName[$name])) {
                $this->ambiguous[$name] = ($this->ambiguous[$name] ?? 1) + 1;

                continue;
            }

            $this->byLoginName[$name] = (int) $row->login_id;
        }

        foreach ($legacy->table('employee_register')->orderBy('employee_id')->get(['employee_id', 'employee_name']) as $row) {
            $name = $this->normalise($row->employee_name);
            $userId = $staffToUser[(int) $row->employee_id] ?? null;

            if ($name === '' || $userId === null || isset($this->byEmployeeName[$name])) {
                continue;
            }

            $this->byEmployeeName[$name] = $userId;
        }
    }

    /**
     * Resolve a display-name column. Login name first, employee name second.
     */
    public function byName(?string $value): ?int
    {
        $name = $this->normalise($value);

        if ($name === '') {
            return null;
        }

        return $this->byLoginName[$name] ?? $this->byEmployeeName[$name] ?? null;
    }

    /**
     * Resolve an integer-id column, but only if that user actually exists.
     *
     * Necessary, not defensive: `discharge_by_id` and `aplist_by` both hold
     * values up to 186000000 alongside real login ids, and neither column has
     * ever had a constraint behind it.
     */
    public function byId(mixed $value): ?int
    {
        $id = (int) $value;

        return ($id > 0 && isset($this->validUserIds[$id])) ? $id : null;
    }

    /**
     * Prefer the id column, fall back to the name column.
     *
     * Used for discharge, where legacy writes both `discharge_by_id` (9,754
     * rows, 4 of them invalid) and `discharge_by` (35,631 rows) — the name is
     * present far more often, so it is not merely a fallback.
     */
    public function byIdOrName(mixed $id, ?string $name): ?int
    {
        return $this->byId($id) ?? $this->byName($name);
    }

    /** @return array<string, int> name => number of logins that claimed it */
    public function ambiguousNames(): array
    {
        return $this->ambiguous;
    }

    private function normalise(?string $value): string
    {
        return strtolower(trim((string) $value));
    }
}
