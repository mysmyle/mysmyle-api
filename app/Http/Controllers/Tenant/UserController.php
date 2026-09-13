<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Staff;
use App\Models\Tenant\User;
use App\Services\UserAccountService;
use App\Support\TenantCache;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    private const WITH_RELATIONS = ['staff', 'role.department', 'createdBy.staff', 'disabledBy.staff'];

    public function index(Request $request)
    {
        $users = User::with(self::WITH_RELATIONS)->get()
            ->map(fn (User $user) => $user->listArray());

        return response()->json(['users' => $users]);
    }

    public function show(Request $request, int $userId)
    {
        $user = User::with(self::WITH_RELATIONS)->findOrFail($userId);

        return response()->json(['user' => $user->detailedArray()]);
    }

    public function store(Request $request, UserAccountService $service)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['staff', 'guest'])],
            'staff_id' => ['nullable', 'required_if:type,staff', 'integer', 'exists:tenant.staff,id'],
            'name' => ['nullable', 'required_if:type,guest', 'string', 'max:255'],
            'email' => [
                'required', 'email',
                Rule::unique('landlord.tenant_users', 'email'),
                Rule::unique('tenant.users', 'email'),
            ],
            // Department + designation (a role) are chosen on the create screen.
            'role_id' => ['required', 'integer', 'exists:tenant.roles,id'],
        ]);

        // Staff accounts get an emailed set-password link — the staff member
        // needs a personal email for it to go to.
        if ($validated['type'] === 'staff' && ! Staff::findOrFail($validated['staff_id'])->personal_email) {
            throw ValidationException::withMessages([
                'staff_id' => ['This staff member has no personal email. Add one on the Staff screen first.'],
            ]);
        }

        // New accounts are always created active.
        $result = $service->create(TenantCache::currentTenantId(), [
            'staff_id' => $validated['type'] === 'staff' ? $validated['staff_id'] : null,
            'name' => $validated['type'] === 'guest' ? $validated['name'] : null,
            'email' => $validated['email'],
            'role_id' => $validated['role_id'],
        ], $request->user()->id);

        $user = User::with(self::WITH_RELATIONS)->findOrFail($result['user']->id);

        AuditLog::record($request->user()->id, 'user.created', User::class, $user->id, [
            'email' => $user->email,
            'type' => $validated['type'],
        ]);

        return response()->json([
            'user' => $user->detailedArray(),
            'temporary_password' => $result['temporary_password'] ?? null,
            'setup_email' => $result['setup_email'] ?? null,
        ], 201);
    }

    public function update(Request $request, int $userId, UserAccountService $service)
    {
        $user = User::findOrFail($userId);
        $isGuest = $user->staff_id === null;
        $previousStatus = $user->status;

        $validated = $request->validate([
            // Only guests carry their own name; staff-linked accounts use staff.name.
            'name' => [$isGuest ? 'required' : 'nullable', 'string', 'max:255'],
            'email' => [
                'required', 'email',
                Rule::unique('landlord.tenant_users', 'email')->ignore($user->email, 'email'),
                Rule::unique('tenant.users', 'email')->ignore($user->id),
            ],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'role_id' => ['present', 'nullable', 'integer', 'exists:tenant.roles,id'],
        ]);

        $service->update(TenantCache::currentTenantId(), $user, [
            'name' => $isGuest ? $validated['name'] : $user->name,
            'email' => $validated['email'],
            'status' => $validated['status'],
            'role_id' => $validated['role_id'],
        ], $request->user()->id);

        $fresh = User::with(self::WITH_RELATIONS)->findOrFail($userId);

        AuditLog::record($request->user()->id, 'user.updated', User::class, $user->id, [
            'status' => ['from' => $previousStatus, 'to' => $validated['status']],
        ]);

        return response()->json(['user' => $fresh->detailedArray()]);
    }

    /**
     * Admin action: reset an account's password.
     *
     * - Staff account: a set-password link is emailed to the staff member's
     *   personal email; the old password stops working until they use it.
     * - Guest account: a temporary password is generated, emailed to the login
     *   email, returned once, and the account is flagged must_change_password.
     *
     * Existing sessions are not invalidated.
     */
    public function resetPassword(Request $request, int $userId, UserAccountService $service)
    {
        $user = User::findOrFail($userId);

        $result = $service->resetPassword(TenantCache::currentTenantId(), $user);

        AuditLog::record($request->user()->id, 'user.password_reset', User::class, $user->id);

        return response()->json([
            'temporary_password' => $result['temporary_password'] ?? null,
            'setup_email' => $result['setup_email'] ?? null,
        ]);
    }

    /**
     * Admin action: immediately invalidate this user's current session(s),
     * without touching their password. ResolveTenantConnection rejects any
     * session issued before this timestamp on its very next request.
     */
    public function forceLogout(Request $request, int $userId)
    {
        $user = User::findOrFail($userId);
        $user->forceFill(['sessions_invalidated_at' => now()])->save();

        AuditLog::record($request->user()->id, 'user.force_logged_out', User::class, $user->id);

        return response()->json(['message' => 'The user has been logged out.']);
    }
}
