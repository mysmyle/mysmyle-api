<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LandlordAuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $admin = LandlordAdmin::where('email', $credentials['email'])->first();

        if ($admin && $admin->password === null) {
            throw ValidationException::withMessages([
                'email' => ['This account has not been set up yet. Check your email for the set-password link.'],
            ]);
        }

        if (! $admin || ! Hash::check($credentials['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if ($admin->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['This account has been disabled.'],
            ]);
        }

        $request->session()->regenerate();
        session()->forget(['tenant_id', 'auth_user_id']);

        session(['landlord_admin_id' => $admin->id]);

        return response()->json([
            'admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email],
        ]);
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        return response()->json(['admin' => $request->user()]);
    }
}
