<?php

namespace Tests\Feature;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use BuildsTenantData;

    private $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->seedDesignations();

        $this->tenant = $this->makeTenant();
        $this->user = $this->makeTenantUser($this->tenant, ['email' => 'member@test-clinic.test']);
    }

    private function change(array $body, ?User $as = null)
    {
        return $this->actingAsTenantUser($as ?? $this->user, $this->tenant)
            ->postJson('/api/password', $body);
    }

    private function validBody(array $overrides = []): array
    {
        // "password" is what makeTenantUser hashes as the current password.
        return array_merge([
            'current_password' => 'password',
            'password' => 'Str0ng-Pass!word',
            'password_confirmation' => 'Str0ng-Pass!word',
        ], $overrides);
    }

    public function test_a_user_can_change_their_own_password(): void
    {
        $this->change($this->validBody())->assertOk();

        $this->assertTrue(Hash::check('Str0ng-Pass!word', $this->user->fresh()->password));
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $this->change($this->validBody(['current_password' => 'wrong-password']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    public function test_the_new_password_must_satisfy_the_policy(): void
    {
        $this->change($this->validBody(['password' => 'weak', 'password_confirmation' => 'weak']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        $this->change($this->validBody(['password_confirmation' => 'mismatch']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_changing_the_password_clears_the_must_change_flag(): void
    {
        $temp = $this->makeTenantUser($this->tenant, [
            'email' => 'temp@test-clinic.test',
            'must_change_password' => true,
        ]);

        $this->change($this->validBody(), $temp)->assertOk();

        $this->assertFalse($temp->fresh()->must_change_password);
    }

    public function test_a_user_on_a_temporary_password_is_blocked_from_the_rest_of_the_api(): void
    {
        $temp = $this->makeTenantUser($this->tenant, [
            'email' => 'locked@test-clinic.test',
            'must_change_password' => true,
        ]);
        $this->grantControlPanel($temp);

        $this->actingAsTenantUser($temp, $this->tenant)
            ->getJson('/api/modules')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');

        $this->actingAsTenantUser($temp, $this->tenant)
            ->getJson('/api/staff')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');
    }

    public function test_a_locked_user_can_still_reach_profile_logout_and_password_endpoints(): void
    {
        $temp = $this->makeTenantUser($this->tenant, [
            'email' => 'locked2@test-clinic.test',
            'must_change_password' => true,
        ]);

        $this->actingAsTenantUser($temp, $this->tenant)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);

        $this->actingAsTenantUser($temp, $this->tenant)->getJson('/api/password-policy')->assertOk();

        $this->change($this->validBody(), $temp)->assertOk();
    }

    public function test_the_password_policy_is_readable_by_any_tenant_user(): void
    {
        $this->actingAsTenantUser($this->user, $this->tenant)
            ->getJson('/api/password-policy')
            ->assertOk()
            ->assertJsonPath('password_policy.min_length', config('password_policy.min_length'));
    }

    public function test_api_user_reports_the_flag_as_false_by_default(): void
    {
        $this->actingAsTenantUser($this->user, $this->tenant)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.must_change_password', false);
    }
}
