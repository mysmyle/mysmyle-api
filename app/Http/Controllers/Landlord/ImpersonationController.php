<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Landlord\AuditLog;
use App\Models\Landlord\Tenant;
use App\Models\Tenant\AuditLog as TenantAuditLog;
use App\Models\Tenant\User;
use App\Services\TenantConnectionResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImpersonationController extends Controller
{
    /** Compact user picker for the tenant detail page's "Log in as" action. */
    public function index(Request $request, int $tenantId, TenantConnectionResolver $resolver)
    {
        $tenant = Tenant::findOrFail($tenantId);

        if (! in_array($tenant->status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED], true)) {
            return response()->json(['users' => []]);
        }

        $resolver->bind($tenant);

        $users = User::with('staff:id,name')->get(['id', 'staff_id', 'name', 'email', 'status'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'display_name' => $user->displayName(),
                'email' => $user->email,
                'status' => $user->status,
            ]);

        return response()->json(['users' => $users]);
    }

    /**
     * Signs the current landlord session in as a tenant user, without their
     * password. The original landlord session is preserved (not destroyed) via
     * impersonator_landlord_admin_id, restored by Tenant\ImpersonationController::stop.
     */
    public function start(Request $request, int $tenantId, int $userId, TenantConnectionResolver $resolver)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $tenant = Tenant::findOrFail($tenantId);

        if ($tenant->status !== Tenant::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'tenant' => ['Only an active tenant can be impersonated.'],
            ]);
        }

        $resolver->bind($tenant);
        $user = User::findOrFail($userId);

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'user' => ['Only an active user can be impersonated.'],
            ]);
        }

        $admin = $request->user();

        TenantAuditLog::record($user->id, 'user.impersonation_started', User::class, $user->id, [
            'landlord_admin_email' => $admin->email,
            'reason' => $validated['reason'],
        ]);

        AuditLog::record($admin->id, 'tenant_user.impersonation_started', Tenant::class, $tenant->id, [
            'user_id' => $user->id,
            'email' => $user->email,
            'reason' => $validated['reason'],
        ], $tenant->id);

        $request->session()->regenerate();
        $request->session()->forget('landlord_admin_id');
        $request->session()->put([
            'tenant_id' => $tenant->id,
            'auth_user_id' => $user->id,
            // compared against users.sessions_invalidated_at on every request
            'session_issued_at' => now()->timestamp,
            'impersonator_landlord_admin_id' => $admin->id,
        ]);

        return response()->json(['message' => 'Impersonation started.']);
    }
}
