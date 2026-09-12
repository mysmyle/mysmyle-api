<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Rules\CompliesWithPasswordPolicy;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * The current tenant user sets their own password. Also the way a
     * system-generated temporary password (must_change_password) is cleared.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', new CompliesWithPasswordPolicy],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ])->save();

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * The active policy, so the change-password / set-password screens can show
     * the requirements. Readable by any authenticated tenant user.
     */
    public function policy()
    {
        return response()->json([
            'password_policy' => PasswordPolicy::current()->toArray(),
        ]);
    }
}
