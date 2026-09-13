<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Rules\CompliesWithPasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /** The current landlord admin sets their own password. */
    public function update(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', new CompliesWithPasswordPolicy],
        ]);

        if (! Hash::check($validated['current_password'], $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $admin->forceFill(['password' => Hash::make($validated['password'])])->save();

        return response()->json(['message' => 'Password updated.']);
    }
}
