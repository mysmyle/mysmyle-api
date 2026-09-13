<?php

namespace Tests\Feature;

use App\Models\Landlord\Tenant;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use BuildsTenantData;

    private $tenant;

    private $superAdmin;

    private $targetUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->tenant = $this->makeTenant();
        $this->superAdmin = $this->makeLandlordAdmin();
        $this->targetUser = $this->makeTenantUser($this->tenant, ['email' => 'admin@test-clinic.test']);
    }

    private function start(array $overrides = [])
    {
        $userId = $overrides['user_id'] ?? $this->targetUser->id;
        unset($overrides['user_id']);

        return $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson(
                "/api/landlord/tenants/{$this->tenant->id}/impersonate/{$userId}",
                $overrides + ['reason' => 'Investigating a support ticket'],
            );
    }

    public function test_listing_tenant_users_requires_a_landlord_admin(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->getJson("/api/landlord/tenants/{$this->tenant->id}/users")
            ->assertStatus(401);
    }

    public function test_lists_tenant_users_for_the_picker(): void
    {
        $response = $this->actingAsLandlordAdmin($this->superAdmin)
            ->getJson("/api/landlord/tenants/{$this->tenant->id}/users")
            ->assertOk();

        $response->assertJsonFragment(['email' => 'admin@test-clinic.test', 'status' => 'active']);
    }

    public function test_starting_impersonation_requires_a_reason(): void
    {
        $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/impersonate/{$this->targetUser->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_cannot_impersonate_into_a_suspended_tenant(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->start()->assertStatus(422);
    }

    public function test_cannot_impersonate_an_inactive_user(): void
    {
        $this->targetUser->update(['status' => 'inactive']);

        $this->start()->assertStatus(422);
    }

    public function test_impersonation_grants_a_working_tenant_session(): void
    {
        $this->start()->assertOk();

        // the impersonating request's own session carried the new tenant context
        $this->assertSame($this->tenant->id, session('tenant_id'));
        $this->assertSame($this->targetUser->id, session('auth_user_id'));
        $this->assertSame($this->superAdmin->id, session('impersonator_landlord_admin_id'));
    }

    public function test_impersonation_is_logged_on_both_sides(): void
    {
        $this->start()->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->targetUser->id,
            'action' => 'user.impersonation_started',
        ]);
        $this->assertDatabaseHas('landlord_audit_logs', [
            'landlord_admin_id' => $this->superAdmin->id,
            'action' => 'tenant_user.impersonation_started',
            'subject_id' => $this->tenant->id,
        ]);
    }

    public function test_stopping_impersonation_requires_an_active_impersonation(): void
    {
        $this->actingAsTenantUser($this->targetUser, $this->tenant)
            ->postJson('/api/impersonate/stop')
            ->assertStatus(422);
    }

    public function test_stopping_impersonation_restores_the_landlord_session(): void
    {
        $this->start()->assertOk();

        $this->withSession([
            'tenant_id' => $this->tenant->id,
            'auth_user_id' => $this->targetUser->id,
            'session_issued_at' => now()->timestamp,
            'impersonator_landlord_admin_id' => $this->superAdmin->id,
        ])->withHeader('Origin', 'http://localhost')
            ->postJson('/api/impersonate/stop')
            ->assertOk();

        $this->assertSame($this->superAdmin->id, session('landlord_admin_id'));
        $this->assertNull(session('tenant_id'));
        $this->assertNull(session('auth_user_id'));
        $this->assertNull(session('impersonator_landlord_admin_id'));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->targetUser->id,
            'action' => 'user.impersonation_ended',
        ]);
        $this->assertDatabaseHas('landlord_audit_logs', [
            'landlord_admin_id' => $this->superAdmin->id,
            'action' => 'tenant_user.impersonation_ended',
        ]);
    }

    public function test_an_impersonated_session_bypasses_the_must_change_password_gate(): void
    {
        $this->targetUser->update(['must_change_password' => true]);
        $this->start()->assertOk();

        $this->withSession([
            'tenant_id' => $this->tenant->id,
            'auth_user_id' => $this->targetUser->id,
            'session_issued_at' => now()->timestamp,
            'impersonator_landlord_admin_id' => $this->superAdmin->id,
        ])->withHeader('Origin', 'http://localhost')
            ->getJson('/api/modules')
            ->assertOk();
    }

    public function test_a_regular_login_still_blocks_on_must_change_password(): void
    {
        $this->targetUser->update(['must_change_password' => true]);

        $this->actingAsTenantUser($this->targetUser, $this->tenant)
            ->getJson('/api/modules')
            ->assertStatus(403);
    }
}
