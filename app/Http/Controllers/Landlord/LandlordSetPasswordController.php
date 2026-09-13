<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordAdmin;
use App\Rules\CompliesWithPasswordPolicy;
use App\Services\LandlordPasswordSetupService;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;

/**
 * Public (no session) endpoints for the emailed set-password link an invited
 * landlord admin follows to choose their first password.
 */
class LandlordSetPasswordController extends Controller
{
    public function show(string $token, LandlordPasswordSetupService $service)
    {
        $row = $service->find($token);

        if (! $row) {
            return response()->json(['message' => 'This link is invalid or has expired.'], 404);
        }

        $admin = LandlordAdmin::findOrFail($row->landlord_admin_id);

        return response()->json([
            'email' => $admin->email,
            'password_policy' => PasswordPolicy::current()->toArray(),
        ]);
    }

    public function store(Request $request, LandlordPasswordSetupService $service)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', new CompliesWithPasswordPolicy],
        ]);

        $service->complete($validated['token'], $validated['password']);

        return response()->json(['message' => 'Password set. You can now sign in.']);
    }
}
