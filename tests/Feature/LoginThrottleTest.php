<?php

namespace Tests\Feature;

use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use BuildsTenantData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
    }

    private function postLogin(string $email, string $password)
    {
        return $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    public function test_tenant_login_locks_out_after_five_failed_attempts(): void
    {
        $tenant = $this->makeTenant();
        $this->makeTenantUser($tenant, ['email' => 'victim@test.test']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postLogin('victim@test.test', 'wrong-password')->assertStatus(422);
        }

        $this->postLogin('victim@test.test', 'wrong-password')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_throttle_is_scoped_per_account(): void
    {
        $tenant = $this->makeTenant();
        $this->makeTenantUser($tenant, ['email' => 'victim@test.test']);
        $this->makeTenantUser($tenant, ['email' => 'bystander@test.test']);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->postLogin('victim@test.test', 'wrong-password');
        }
        $this->postLogin('victim@test.test', 'wrong-password')->assertStatus(429);

        // a different account from the same IP is unaffected (still within the per-IP cap)
        $this->postLogin('bystander@test.test', 'wrong-password')->assertStatus(422);
    }

    public function test_a_correct_login_succeeds_and_is_not_throttled(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeTenantUser($tenant, ['email' => 'real@test.test']);

        $this->postLogin('real@test.test', 'nope')->assertStatus(422);

        $this->postLogin('real@test.test', 'password')
            ->assertOk()
            ->assertJsonPath('user.email', 'real@test.test')
            ->assertJsonPath('tenant.id', $tenant->id);
    }

    public function test_landlord_login_is_also_rate_limited(): void
    {
        $this->makeLandlordAdmin(['email' => 'super@test.test']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withHeader('Origin', 'http://localhost')
                ->postJson('/api/landlord/login', ['email' => 'super@test.test', 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/landlord/login', ['email' => 'super@test.test', 'password' => 'wrong'])
            ->assertStatus(429);
    }
}
