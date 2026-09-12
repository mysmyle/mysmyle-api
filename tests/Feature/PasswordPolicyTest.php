<?php

namespace Tests\Feature;

use App\Rules\CompliesWithPasswordPolicy;
use App\Services\PasswordGenerator;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use BuildsTenantData;

    private function generator(): PasswordGenerator
    {
        return app(PasswordGenerator::class);
    }

    public function test_the_policy_falls_back_to_config_when_no_row_is_stored(): void
    {
        $admin = $this->makeLandlordAdmin();

        $this->actingAsLandlordAdmin($admin)
            ->getJson('/api/landlord/settings/password-policy')
            ->assertOk()
            ->assertJsonPath('password_policy.min_length', config('password_policy.min_length'))
            ->assertJsonPath('password_policy.symbols', config('password_policy.symbols'));
    }

    public function test_reading_the_policy_requires_a_landlord_admin(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->getJson('/api/landlord/settings/password-policy')
            ->assertStatus(401);
    }

    public function test_a_super_admin_can_update_the_policy_and_it_takes_effect_immediately(): void
    {
        $admin = $this->makeLandlordAdmin();

        $this->actingAsLandlordAdmin($admin)
            ->putJson('/api/landlord/settings/password-policy', [
                'min_length' => 16,
                'min_uppercase' => 2,
                'min_lowercase' => 2,
                'min_digits' => 2,
                'min_symbols' => 2,
                'symbols' => '#$%',
                'exclude_ambiguous' => false,
            ])
            ->assertOk()
            ->assertJsonPath('password_policy.min_length', 16)
            ->assertJsonPath('password_policy.symbols', '#$%');

        // a fresh resolve (no stale cache) sees the new values
        $policy = PasswordPolicy::current();
        $this->assertSame(16, $policy->minLength);
        $this->assertSame('#$%', $policy->symbols);

        // ...and the generator now produces 16-char passwords
        $this->assertSame(16, strlen($this->generator()->generate()));
    }

    public function test_symbols_are_sanitised_to_punctuation_only(): void
    {
        $admin = $this->makeLandlordAdmin();

        $this->actingAsLandlordAdmin($admin)
            ->putJson('/api/landlord/settings/password-policy', [
                'min_length' => 12, 'min_uppercase' => 1, 'min_lowercase' => 1,
                'min_digits' => 1, 'min_symbols' => 1,
                'symbols' => 'ab! @#!', 'exclude_ambiguous' => true,
            ])
            ->assertOk()
            ->assertJsonPath('password_policy.symbols', '!@#');
    }

    public function test_the_required_minima_cannot_exceed_the_length(): void
    {
        $admin = $this->makeLandlordAdmin();

        $this->actingAsLandlordAdmin($admin)
            ->putJson('/api/landlord/settings/password-policy', [
                'min_length' => 8, 'min_uppercase' => 3, 'min_lowercase' => 3,
                'min_digits' => 3, 'min_symbols' => 3,
                'symbols' => '!@#', 'exclude_ambiguous' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('min_length');
    }

    public function test_requiring_symbols_with_none_configured_is_rejected(): void
    {
        $admin = $this->makeLandlordAdmin();

        $this->actingAsLandlordAdmin($admin)
            ->putJson('/api/landlord/settings/password-policy', [
                'min_length' => 12, 'min_uppercase' => 1, 'min_lowercase' => 1,
                'min_digits' => 1, 'min_symbols' => 1,
                'symbols' => '', 'exclude_ambiguous' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('symbols');
    }

    public function test_generated_passwords_always_satisfy_the_policy(): void
    {
        $policies = [
            PasswordPolicy::fromArray(config('password_policy')),
            PasswordPolicy::fromArray([
                'min_length' => 20, 'min_uppercase' => 3, 'min_lowercase' => 0,
                'min_digits' => 4, 'min_symbols' => 2, 'symbols' => '@#$%',
                'exclude_ambiguous' => false,
            ]),
        ];

        foreach ($policies as $policy) {
            for ($i = 0; $i < 200; $i++) {
                $password = $this->generator()->generate($policy);
                $this->assertSame([], $policy->violations($password), "failed: {$password}");
            }
        }
    }

    public function test_generated_passwords_exclude_ambiguous_characters_when_configured(): void
    {
        $policy = PasswordPolicy::fromArray(config('password_policy')); // exclude_ambiguous = true

        for ($i = 0; $i < 200; $i++) {
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $this->generator()->generate($policy));
        }
    }

    public function test_the_validation_rule_reports_each_unmet_requirement(): void
    {
        $validator = Validator::make(
            ['password' => 'short'],
            ['password' => [new CompliesWithPasswordPolicy]],
        );

        $this->assertTrue($validator->fails());
        $messages = $validator->errors()->get('password');
        $this->assertContains('The password must be at least 12 characters.', $messages);
        $this->assertContains('The password must contain at least one uppercase letter.', $messages);
    }

    public function test_the_validation_rule_accepts_a_compliant_password(): void
    {
        $password = $this->generator()->generate(PasswordPolicy::fromArray(config('password_policy')));

        $validator = Validator::make(
            ['password' => $password],
            ['password' => [new CompliesWithPasswordPolicy]],
        );

        $this->assertFalse($validator->fails(), "rejected a generated password: {$password}");
    }
}
