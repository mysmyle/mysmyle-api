<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Module;
use App\Models\Landlord\TenantUser;
use App\Models\Tenant\User;
use App\Services\TenantConnectionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, TenantConnectionResolver $resolver)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $tenantUser = TenantUser::where('email', $credentials['email'])->first();

        if (! $tenantUser || $tenantUser->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $tenant = $resolver->resolveAndBind($tenantUser->tenant_id);

        if (! $tenant) {
            throw ValidationException::withMessages([
                'email' => ['This clinic account is not active.'],
            ]);
        }

        $user = User::where('email', $credentials['email'])->first();

        if ($user && $user->password === null) {
            throw ValidationException::withMessages([
                'email' => ['This account has not been set up yet. Check your email for the set-password link.'],
            ]);
        }

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['This account has been disabled.'],
            ]);
        }

        $request->session()->regenerate();
        session()->forget('landlord_admin_id');

        session([
            'tenant_id' => $tenant->id,
            'auth_user_id' => $user->id,
        ]);

        $user->update(['last_login_at' => now()]);

        return response()->json([
            'user' => ['id' => $user->id, 'email' => $user->email],
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
        ]);
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    public function user(Request $request)
    {
        $user = $request->user()->load(['staff', 'role.department']);

        $controlPanelId = Module::controlPanelId();

        $response = $user->detailedArray() + [
            'can_control_panel' => $controlPanelId ? $user->hasModuleAccess($controlPanelId) : false,
        ];

        return response()->json([
            'user' => $response,
            'tenant_id' => session('tenant_id'),
        ]);
    }
}
