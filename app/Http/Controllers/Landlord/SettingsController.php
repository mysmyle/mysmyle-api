<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Landlord\AuditLog;
use App\Support\PasswordPolicy;
use App\Support\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function passwordPolicy()
    {
        return response()->json([
            'password_policy' => PasswordPolicy::current()->toArray(),
        ]);
    }

    public function updatePasswordPolicy(Request $request, SettingsRepository $settings)
    {
        $validated = $request->validate([
            'min_length' => ['required', 'integer', 'min:8', 'max:128'],
            'min_uppercase' => ['required', 'integer', 'min:0', 'max:64'],
            'min_lowercase' => ['required', 'integer', 'min:0', 'max:64'],
            'min_digits' => ['required', 'integer', 'min:0', 'max:64'],
            'min_symbols' => ['required', 'integer', 'min:0', 'max:64'],
            'symbols' => ['present', 'string', 'max:64'],
            'exclude_ambiguous' => ['required', 'boolean'],
        ]);

        // De-dupe symbols and drop whitespace / anything alphanumeric.
        $symbols = preg_replace('/[\s\w]/', '', $validated['symbols']);
        $symbols = implode('', array_unique(str_split($symbols === '' ? '' : $symbols)));

        $minimaSum = $validated['min_uppercase'] + $validated['min_lowercase']
            + $validated['min_digits'] + $validated['min_symbols'];

        if ($minimaSum > $validated['min_length']) {
            throw ValidationException::withMessages([
                'min_length' => ['The minimum length must be at least the sum of the required character counts.'],
            ]);
        }

        if ($validated['min_symbols'] > 0 && $symbols === '') {
            throw ValidationException::withMessages([
                'symbols' => ['Provide at least one symbol when symbols are required.'],
            ]);
        }

        $policy = [
            'min_length' => $validated['min_length'],
            'min_uppercase' => $validated['min_uppercase'],
            'min_lowercase' => $validated['min_lowercase'],
            'min_digits' => $validated['min_digits'],
            'min_symbols' => $validated['min_symbols'],
            'symbols' => $symbols,
            'exclude_ambiguous' => $validated['exclude_ambiguous'],
        ];

        $settings->put('password_policy', $policy);

        AuditLog::record($request->user()->id, 'settings.password_policy_updated', null, null, $policy);

        return response()->json([
            'password_policy' => PasswordPolicy::current()->toArray(),
        ]);
    }
}
