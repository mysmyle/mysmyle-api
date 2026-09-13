<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Landlord\AuditLog;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\LandlordPasswordSetupToken;
use App\Services\LandlordPasswordSetupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LandlordAdminController extends Controller
{
    public function index(Request $request)
    {
        $pending = LandlordPasswordSetupToken::whereNull('used_at')->pluck('landlord_admin_id')->flip();

        $admins = LandlordAdmin::all(['id', 'name', 'email', 'status', 'role'])
            ->map(fn (LandlordAdmin $admin) => $admin->toArray() + [
                'setup_pending' => $pending->has($admin->id),
            ]);

        return response()->json(['admins' => $admins]);
    }

    public function store(Request $request, LandlordPasswordSetupService $service)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('landlord.landlord_admins', 'email')],
            'role' => ['required', Rule::in([LandlordAdmin::ROLE_SUPER_ADMIN, LandlordAdmin::ROLE_SUPPORT])],
        ]);

        $admin = LandlordAdmin::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => null,
            'status' => 'active',
            'role' => $validated['role'],
        ]);

        $service->sendSetupLink($admin);

        AuditLog::record($request->user()->id, 'landlord_admin.created', LandlordAdmin::class, $admin->id, [
            'name' => $admin->name, 'email' => $admin->email, 'role' => $admin->role,
        ]);

        return response()->json(['admin' => $admin], 201);
    }

    public function update(Request $request, int $adminId)
    {
        $admin = LandlordAdmin::findOrFail($adminId);
        $previousStatus = $admin->status;
        $previousRole = $admin->role;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('landlord.landlord_admins', 'email')->ignore($admin->id)],
            'status' => ['required', Rule::in(['active', 'disabled'])],
            'role' => ['required', Rule::in([LandlordAdmin::ROLE_SUPER_ADMIN, LandlordAdmin::ROLE_SUPPORT])],
        ]);

        if ($validated['status'] === 'disabled' && $admin->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'status' => ['You cannot disable your own account.'],
            ]);
        }

        if ($validated['status'] === 'disabled') {
            $otherActiveAdmins = LandlordAdmin::where('status', 'active')
                ->where('id', '!=', $admin->id)
                ->exists();

            if (! $otherActiveAdmins) {
                throw ValidationException::withMessages([
                    'status' => ['At least one active admin must remain.'],
                ]);
            }
        }

        // The resulting record must not be the last active super admin, so
        // there's always someone who can manage tenants, admins and settings.
        $remainsActiveSuperAdmin = $validated['status'] === 'active'
            && $validated['role'] === LandlordAdmin::ROLE_SUPER_ADMIN;

        if (! $remainsActiveSuperAdmin) {
            $otherActiveSuperAdmins = LandlordAdmin::where('status', 'active')
                ->where('role', LandlordAdmin::ROLE_SUPER_ADMIN)
                ->where('id', '!=', $admin->id)
                ->exists();

            if (! $otherActiveSuperAdmins) {
                throw ValidationException::withMessages([
                    'role' => ['At least one active super admin must remain.'],
                ]);
            }
        }

        $admin->update($validated);

        AuditLog::record($request->user()->id, 'landlord_admin.updated', LandlordAdmin::class, $admin->id, [
            'status' => ['from' => $previousStatus, 'to' => $admin->status],
            'role' => ['from' => $previousRole, 'to' => $admin->role],
        ]);

        return response()->json(['admin' => $admin]);
    }

    public function resendSetupLink(Request $request, int $adminId, LandlordPasswordSetupService $service)
    {
        $admin = LandlordAdmin::findOrFail($adminId);

        $service->reissueForAdmin($admin);

        AuditLog::record($request->user()->id, 'landlord_admin.setup_link_resent', LandlordAdmin::class, $admin->id);

        return response()->json(['message' => 'A new set-password link was emailed to the admin.']);
    }

    /** Clears the admin's password and emails a fresh set-password link. */
    public function resetPassword(Request $request, int $adminId, LandlordPasswordSetupService $service)
    {
        $admin = LandlordAdmin::findOrFail($adminId);
        $admin->forceFill(['password' => null])->save();

        $service->sendSetupLink($admin);

        AuditLog::record($request->user()->id, 'landlord_admin.password_reset', LandlordAdmin::class, $admin->id);

        return response()->json(['message' => 'A set-password link was emailed to the admin.']);
    }
}
