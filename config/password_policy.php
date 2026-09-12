<?php

/*
|--------------------------------------------------------------------------
| Password policy defaults
|--------------------------------------------------------------------------
|
| These are the fallback values. The live policy is a single landlord
| `settings` row ("password_policy") that overrides any of these keys and is
| edited by a super admin on /sa/settings. See App\Support\SettingsRepository
| and App\Support\PasswordPolicy.
|
| The policy governs three things: generated temporary passwords (must be
| compliant by construction), the tenant "set a new password" screens, and the
| provisioning "set your password" link.
|
*/

return [
    'min_length' => 12,
    'min_uppercase' => 1,
    'min_lowercase' => 1,
    'min_digits' => 1,
    'min_symbols' => 1,

    // Symbols allowed in generated passwords and accepted from users.
    'symbols' => '!@#$%^&*-_=+',

    // Drop visually ambiguous characters (0/O, 1/l/I) so a generated password
    // is safe to read aloud or retype. Only affects letters and digits — the
    // symbol set is used as configured.
    'exclude_ambiguous' => true,
];
