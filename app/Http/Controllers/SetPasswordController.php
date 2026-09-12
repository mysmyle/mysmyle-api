<?php

namespace App\Http\Controllers;

use App\Rules\CompliesWithPasswordPolicy;
use App\Services\PasswordSetupService;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;

/**
 * Public (no session) endpoints for the emailed set-password link a newly
 * provisioned tenant admin follows to choose their first password.
 */
class SetPasswordController extends Controller
{
    public function show(string $token, PasswordSetupService $service)
    {
        $row = $service->find($token);

        if (! $row) {
            return response()->json(['message' => 'This link is invalid or has expired.'], 404);
        }

        return response()->json([
            'email' => $row->email,
            'password_policy' => PasswordPolicy::current()->toArray(),
        ]);
    }

    public function store(Request $request, PasswordSetupService $service)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', new CompliesWithPasswordPolicy],
        ]);

        $service->complete($validated['token'], $validated['password']);

        return response()->json(['message' => 'Password set. You can now sign in.']);
    }
}
