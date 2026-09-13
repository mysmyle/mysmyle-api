<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Landlord\AuditLog as LandlordAuditLog;
use App\Models\Landlord\Tenant;
use App\Models\Tenant\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImpersonationController extends Controller
{
    /** Restores the original landlord session that started this impersonation. */
    public function stop(Request $request)
    {
        $adminId = session('impersonator_landlord_admin_id');

        if (! $adminId) {
            throw ValidationException::withMessages([
                'impersonation' => ['You are not currently impersonating a user.'],
            ]);
        }

        $user = $request->user();
        $tenantId = session('tenant_id');

        AuditLog::record($user->id, 'user.impersonation_ended', $user::class, $user->id, [
            'landlord_admin_id' => $adminId,
        ]);

        LandlordAuditLog::record($adminId, 'tenant_user.impersonation_ended', Tenant::class, $tenantId, [
            'user_id' => $user->id,
            'email' => $user->email,
        ], $tenantId);

        $request->session()->regenerate();
        $request->session()->forget(['tenant_id', 'auth_user_id', 'session_issued_at', 'impersonator_landlord_admin_id']);
        $request->session()->put('landlord_admin_id', $adminId);

        return response()->json(['message' => 'Impersonation ended.']);
    }
}
