<?php

namespace App\Console\Legacy;

/**
 * legacy.login -> tenant.users (+ landlord.tenant_users for accounts that can sign in)
 *
 * Primary keys are preserved: users.id is the legacy login_id.
 *
 * Passwords are the delicate part. Legacy holds three password columns; only
 * `hash_password` is trustworthy, and only where the legacy app would itself
 * have accepted it (`hash_password IS NOT NULL AND status = 'allow'` — see
 * login_functions_v3.php). The older `password` column holds 180 raw MD5 hashes
 * and roughly 70 values short enough to be plaintext, and is never read here.
 *
 * Everything else lands with password = NULL, which the new AuthController
 * already treats as "not set up yet — use the set-password link".
 *
 * role_id, permissions and must_change_password are app-owned and are never
 * written by this importer.
 */
class LegacyUserImporter extends BaseLegacyImporter
{
    public function label(): string
    {
        return 'users';
    }

    public function run(): void
    {
        $validStaffIds = $this->tenant()->table('staff')->pluck('id')->flip()->toArray();

        $batch = [];
        $landlordBatch = [];
        $seenEmails = [];
        $now = now()->toDateTimeString();
        $tenantId = $this->tenantId;

        foreach ($this->legacy()->table('login')->lazyById(500, 'login_id') as $row) {
            $this->read++;

            $email = strtolower(trim((string) ($row->email_address ?? '')));

            if ($email === '') {
                $this->skip($row->login_id, 'no email — users.email is NOT NULL UNIQUE');

                continue;
            }

            if (isset($seenEmails[$email])) {
                $this->skip($row->login_id, "duplicate email {$email} — already taken by login_id {$seenEmails[$email]}");

                continue;
            }

            $seenEmails[$email] = (int) $row->login_id;

            $isActive = strtolower(trim((string) ($row->status ?? ''))) === 'allow';
            $staffId = $this->validId($row->user_info ?? null, $validStaffIds);

            // Carried over only when the legacy app would have accepted it too.
            // Cost is 12 on both sides, so these validate against Hash::check()
            // unchanged and the 44 keep their current password.
            $hash = (string) ($row->hash_password ?? '');
            $password = ($isActive && str_starts_with($hash, '$2y$')) ? $hash : null;

            $batch[] = [
                'id' => (int) $row->login_id,
                'staff_id' => $staffId,
                // Staff-linked accounts read their display name from the staff
                // record (User::displayName()), so duplicating it here would
                // just be a second copy to drift. Guest accounts have no staff
                // row, so they keep their own name.
                'name' => $staffId === null ? (trim((string) ($row->user_name ?? '')) ?: null) : null,
                'email' => $email,
                'password' => $password,
                'status' => $isActive ? 'active' : 'inactive',
                'created_at' => $this->parseLegacyDateTime($row->created_date ?? null) ?? $now,
                // 137 of 255 legacy rows hold a zero date here — the exact column
                // that rolled under to -0001-11-30 and broke the old live sync.
                'updated_at' => $this->parseLegacyDateTime($row->updated_date ?? null) ?? $now,
            ];

            $this->written++;

            // Only accounts that can actually authenticate get a landlord routing
            // row. Without one, login is refused before the password is even
            // checked — that is what keeps the dead legacy accounts inert.
            if ($password !== null) {
                $landlordBatch[] = [
                    'email' => $email,
                    'tenant_id' => $tenantId,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $this->upsertBatch('users', $batch, ['id'], [
            'staff_id', 'name', 'email', 'status', 'updated_at',
        ]);

        $this->info(count($landlordBatch).' account(s) can sign in; the rest have no password and no landlord routing row.');

        if (! $this->dryRun && $landlordBatch) {
            foreach (array_chunk($landlordBatch, 500) as $slice) {
                $this->landlord()->table('tenant_users')->upsert($slice, ['email'], ['tenant_id', 'status', 'updated_at']);
            }
        }
    }
}
