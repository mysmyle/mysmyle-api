<?php

namespace Tests\Feature;

use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class ForceLogoutTest extends TestCase
{
    use BuildsTenantData;

    private $tenant;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();

        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeTenantUser($this->tenant, ['email' => 'admin@test-clinic.test']);
        $this->grantControlPanel($this->admin);
    }

    public function test_force_logout_requires_control_panel_access(): void
    {
        $target = $this->makeGuestUser($this->tenant, 'Target', ['email' => 'target@test-clinic.test']);
        $plain = $this->makeGuestUser($this->tenant, 'Plain', ['email' => 'plain@test-clinic.test']);

        $this->actingAsTenantUser($plain, $this->tenant)
            ->postJson("/api/users/{$target->id}/force-logout")
            ->assertStatus(403);
    }

    public function test_a_users_existing_session_is_rejected_after_force_logout(): void
    {
        $target = $this->makeGuestUser($this->tenant, 'Target', ['email' => 'target@test-clinic.test']);

        // simulate a session that was already issued a minute ago
        $oldSession = ['session_issued_at' => now()->subMinute()->timestamp];

        $this->actingAsTenantUser($target, $this->tenant, $oldSession)
            ->getJson('/api/user')
            ->assertOk();

        $this->actingAsTenantUser($this->admin, $this->tenant)
            ->postJson("/api/users/{$target->id}/force-logout")
            ->assertOk();

        $this->assertNotNull($target->fresh()->sessions_invalidated_at);

        // the same (old) session is now rejected
        $this->actingAsTenantUser($target, $this->tenant, $oldSession)
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_a_fresh_login_after_force_logout_still_works(): void
    {
        $target = $this->makeGuestUser($this->tenant, 'Target', ['email' => 'target@test-clinic.test']);

        $this->actingAsTenantUser($this->admin, $this->tenant)
            ->postJson("/api/users/{$target->id}/force-logout")
            ->assertOk();

        // a session issued now (i.e. after logging back in) is unaffected
        $this->actingAsTenantUser($target, $this->tenant)
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_force_logout_is_audit_logged(): void
    {
        $target = $this->makeGuestUser($this->tenant, 'Target', ['email' => 'target@test-clinic.test']);

        $this->actingAsTenantUser($this->admin, $this->tenant)
            ->postJson("/api/users/{$target->id}/force-logout")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'user.force_logged_out',
            'subject_id' => $target->id,
        ]);
    }
}
