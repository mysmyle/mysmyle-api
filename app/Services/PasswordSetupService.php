<?php

namespace App\Services;

use App\Mail\SetPasswordLinkMail;
use App\Models\Landlord\PasswordSetupToken;
use App\Models\Landlord\Tenant;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mints, validates and consumes the set-password links: the provisioned tenant
 * admin, and every staff-linked user account (created or reset). The token is
 * keyed to the login email (identifies the user); the link is emailed to a real
 * inbox that may differ — a staff member's personal email.
 */
class PasswordSetupService
{
    public const TTL_HOURS = 48;

    public function __construct(protected TenantConnectionResolver $resolver) {}

    /**
     * Issue a fresh token for a login email + tenant, invalidating any earlier
     * unused ones. Returns the plaintext value for the emailed link.
     */
    public function issue(int $tenantId, string $loginEmail): string
    {
        PasswordSetupToken::where('tenant_id', $tenantId)
            ->where('email', $loginEmail)
            ->whereNull('used_at')
            ->delete();

        $plain = Str::random(64);

        PasswordSetupToken::create([
            'tenant_id' => $tenantId,
            'email' => $loginEmail,
            'token' => hash('sha256', $plain),
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);

        return $plain;
    }

    /**
     * Issue a token for $loginEmail and email the set-password link to
     * $recipientEmail (a real inbox; for a staff user, their personal email).
     */
    public function sendSetupLink(int $tenantId, string $loginEmail, string $recipientEmail, string $name, string $clinicName): void
    {
        $token = $this->issue($tenantId, $loginEmail);

        Mail::to($recipientEmail)->send(new SetPasswordLinkMail(
            $recipientEmail, $name, $clinicName, $token, self::TTL_HOURS,
        ));
    }

    /**
     * Re-issue and re-email the set-password link for a tenant whose admin has
     * not chosen a password yet (link expired or lost).
     */
    public function reissueForTenant(Tenant $tenant): void
    {
        if (! $this->resolver->resolveAndBind($tenant->id)) {
            throw ValidationException::withMessages([
                'tenant' => ['This clinic account is not available.'],
            ]);
        }

        $admin = User::whereNull('password')->first();

        if (! $admin) {
            throw ValidationException::withMessages([
                'tenant' => ["This clinic's admin has already set up their account."],
            ]);
        }

        $recipient = $admin->staff?->personal_email ?? $admin->email;

        $this->sendSetupLink($tenant->id, $admin->email, $recipient, $admin->displayName() ?? '', $tenant->name);
    }

    /** The valid (unused, unexpired) row for a plaintext token, or null. */
    public function find(string $plain): ?PasswordSetupToken
    {
        return PasswordSetupToken::where('token', hash('sha256', $plain))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Set the tenant user's password from a valid token and consume it.
     * `$password` must already be policy-checked by the caller.
     */
    public function complete(string $plain, string $password): void
    {
        $row = $this->find($plain);

        if (! $row) {
            throw ValidationException::withMessages([
                'token' => ['This link is invalid or has expired.'],
            ]);
        }

        $tenant = $this->resolver->resolveAndBind($row->tenant_id);

        if (! $tenant) {
            throw ValidationException::withMessages([
                'token' => ['This clinic account is not available.'],
            ]);
        }

        $user = User::where('email', $row->email)->firstOrFail();

        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => false,
        ])->save();

        $row->update(['used_at' => now()]);
    }
}
