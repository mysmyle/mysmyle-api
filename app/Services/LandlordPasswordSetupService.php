<?php

namespace App\Services;

use App\Mail\LandlordSetPasswordLinkMail;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\LandlordPasswordSetupToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mints, validates and consumes the set-password links an invited landlord
 * admin follows to choose their first password. Mirrors PasswordSetupService
 * on the tenant side but is kept separate — no tenant connection involved.
 */
class LandlordPasswordSetupService
{
    public const TTL_HOURS = 48;

    /**
     * Issue a fresh token for this admin, invalidating any earlier unused ones.
     * Returns the plaintext value for the emailed link.
     */
    public function issue(int $landlordAdminId): string
    {
        LandlordPasswordSetupToken::where('landlord_admin_id', $landlordAdminId)
            ->whereNull('used_at')
            ->delete();

        $plain = Str::random(64);

        LandlordPasswordSetupToken::create([
            'landlord_admin_id' => $landlordAdminId,
            'token' => hash('sha256', $plain),
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);

        return $plain;
    }

    public function sendSetupLink(LandlordAdmin $admin): void
    {
        $token = $this->issue($admin->id);

        Mail::to($admin->email)->send(new LandlordSetPasswordLinkMail(
            $admin->email, $admin->name, $token, self::TTL_HOURS,
        ));
    }

    /**
     * Re-issue and re-email the link for an admin who has not chosen a
     * password yet (link expired or lost).
     */
    public function reissueForAdmin(LandlordAdmin $admin): void
    {
        if ($admin->password !== null) {
            throw ValidationException::withMessages([
                'admin' => ['This admin has already set up their account.'],
            ]);
        }

        $this->sendSetupLink($admin);
    }

    /** The valid (unused, unexpired) row for a plaintext token, or null. */
    public function find(string $plain): ?LandlordPasswordSetupToken
    {
        return LandlordPasswordSetupToken::where('token', hash('sha256', $plain))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Set the admin's password from a valid token and consume it.
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

        $admin = LandlordAdmin::findOrFail($row->landlord_admin_id);
        $admin->forceFill(['password' => Hash::make($password)])->save();

        $row->update(['used_at' => now()]);
    }
}
