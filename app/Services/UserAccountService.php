<?php

namespace App\Services;

use App\Mail\TemporaryPasswordMail;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantUser;
use App\Models\Tenant\Staff;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Throwable;

class UserAccountService
{
    public function __construct(
        protected PasswordGenerator $passwords,
        protected PasswordSetupService $setup,
    ) {}

    /**
     * Create a tenant user account: the landlord-side login row + the tenant-side
     * user (always active), optionally assigned a role (which snapshot-copies its
     * template).
     *
     * - Staff account: no password; a set-password link is emailed to the staff
     *   member's personal_email and the user chooses their own password.
     * - Guest account: a policy-compliant temporary password is generated,
     *   emailed to the login email, returned once for the admin, and the account
     *   is flagged must_change_password (forced change on first sign-in).
     *
     * @param  array{staff_id?: int|null, name?: string|null, email: string, role_id?: int|null}  $data
     * @return array{user: User, temporary_password?: string, setup_email?: string}
     */
    public function create(int $tenantId, array $data, ?int $actorId = null): array
    {
        $isStaff = ! empty($data['staff_id']);
        $plainPassword = $isStaff ? null : $this->passwords->generate();

        $tenantUser = TenantUser::create([
            'email' => $data['email'],
            'tenant_id' => $tenantId,
            'status' => 'active',
        ]);

        try {
            $user = DB::connection('tenant')->transaction(function () use ($tenantId, $data, $plainPassword, $isStaff, $actorId) {
                $attributes = [
                    'staff_id' => $data['staff_id'] ?? null,
                    'name' => $data['name'] ?? null,
                    'email' => $data['email'],
                    'status' => 'active',
                    // guests are forced to change on first login; staff set their own via the link
                    'must_change_password' => ! $isStaff,
                    'created_by' => $actorId,
                ];

                if (! $isStaff) {
                    $attributes['password'] = Hash::make($plainPassword);
                }

                $user = User::create($attributes);

                if (! empty($data['role_id'])) {
                    app(UserRoleService::class)->assignRole($user, (int) $data['role_id'], $tenantId);
                }

                return $user;
            });
        } catch (Throwable $e) {
            $tenantUser->delete(); // undo the landlord row — it can't join the transaction above
            throw $e;
        }

        if ($isStaff) {
            $staff = Staff::findOrFail($data['staff_id']);
            $this->setup->sendSetupLink(
                $tenantId, $user->email, $staff->personal_email, $staff->name, $this->clinicName($tenantId),
            );

            return ['user' => $user, 'setup_email' => $staff->personal_email];
        }

        $this->emailTemporaryPassword($user, $plainPassword);

        return ['user' => $user, 'temporary_password' => $plainPassword];
    }

    /**
     * Reset an account's password.
     *
     * - Staff account: clears the password and emails a fresh set-password link
     *   to the staff member's personal_email. Returns the recipient address.
     * - Guest account: generates a temporary password (forced change on next
     *   sign-in), emails it, and returns it once.
     *
     * @return array{temporary_password?: string, setup_email?: string}
     */
    public function resetPassword(int $tenantId, User $user): array
    {
        if ($user->staff_id !== null) {
            $staff = Staff::findOrFail($user->staff_id);

            $user->forceFill(['password' => null, 'must_change_password' => false])->save();

            $this->setup->sendSetupLink(
                $tenantId, $user->email, $staff->personal_email, $staff->name, $this->clinicName($tenantId),
            );

            return ['setup_email' => $staff->personal_email];
        }

        $plainPassword = $this->passwords->generate();

        $user->forceFill([
            'password' => Hash::make($plainPassword),
            'must_change_password' => true,
        ])->save();

        $this->emailTemporaryPassword($user, $plainPassword);

        return ['temporary_password' => $plainPassword];
    }

    private function emailTemporaryPassword(User $user, string $plainPassword): void
    {
        Mail::to($user->email)->send(
            new TemporaryPasswordMail($user->email, $user->displayName() ?? '', $plainPassword),
        );
    }

    private function clinicName(int $tenantId): string
    {
        return Tenant::find($tenantId)?->name ?? config('app.name');
    }

    /**
     * Update an account's own fields. Email + status are mirrored to the
     * landlord-side login row. Passing `role_id` (including null) reassigns the
     * role. Passwords are not changed here.
     *
     * @param  array{name?: string|null, email: string, status: string, role_id?: int|null}  $data
     */
    public function update(int $tenantId, User $user, array $data, ?int $actorId = null): User
    {
        $loginRow = TenantUser::where('tenant_id', $tenantId)->where('email', $user->email)->firstOrFail();
        $originalEmail = $loginRow->email;
        $originalStatus = $loginRow->status;
        $isBeingDisabled = $user->status !== 'inactive' && $data['status'] === 'inactive';
        $isBeingReactivated = $user->status !== 'active' && $data['status'] === 'active';

        $loginRow->update(['email' => $data['email'], 'status' => $data['status']]);

        try {
            DB::connection('tenant')->transaction(function () use ($user, $tenantId, $data, $actorId, $isBeingDisabled, $isBeingReactivated) {
                $user->fill([
                    'name' => $data['name'] ?? null,
                    'email' => $data['email'],
                    'status' => $data['status'],
                ]);

                if ($isBeingDisabled) {
                    $user->disabled_by = $actorId;
                    $user->disabled_at = now();
                } elseif ($isBeingReactivated) {
                    $user->disabled_by = null;
                    $user->disabled_at = null;
                }

                $user->save();

                if (array_key_exists('role_id', $data)) {
                    $roleId = $data['role_id'] ? (int) $data['role_id'] : null;
                    app(UserRoleService::class)->assignRole($user, $roleId, $tenantId);
                }
            });
        } catch (Throwable $e) {
            $loginRow->update(['email' => $originalEmail, 'status' => $originalStatus]);
            throw $e;
        }

        return $user->fresh();
    }
}
