<?php

namespace App\Rules;

use App\Support\PasswordPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a user-supplied password against the active PasswordPolicy. Every
 * unmet requirement is reported as its own message.
 */
class CompliesWithPasswordPolicy implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        foreach (PasswordPolicy::current()->violations($value) as $violation) {
            $fail($violation);
        }
    }
}
