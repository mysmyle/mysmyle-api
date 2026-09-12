<?php

namespace Tests\Feature;

use App\Models\Landlord\Tenant;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class TenantSuspensionTest extends TestCase
{
    use BuildsTenantData;

    private $tenant;

    private $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->tenant = $this->makeTenant();
        $this->superAdmin = $this->makeLandlordAdmin();
    }

    public function test_suspending_a_tenant_requires_a_landlord_admin(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/suspend")
            ->assertStatus(401);
    }

    public function test_a_super_admin_can_suspend_an_active_tenant(): void
    {
        $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/suspend")
            ->assertOk()
            ->assertJsonPath('tenant.status', Tenant::STATUS_SUSPENDED);

        $this->assertDatabaseHas('tenants', ['id' => $this->tenant->id, 'status' => Tenant::STATUS_SUSPENDED]);
        $this->assertDatabaseHas('landlord_audit_logs', [
            'landlord_admin_id' => $this->superAdmin->id,
            'action' => 'tenant.suspended',
            'subject_id' => $this->tenant->id,
        ]);
    }

    public function test_suspending_an_already_suspended_tenant_is_blocked(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/suspend")
            ->assertStatus(422);
    }

    public function test_a_suspended_tenants_users_are_blocked(): void
    {
        $user = $this->makeTenantUser($this->tenant);
        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->actingAsTenantUser($user, $this->tenant)
            ->getJson('/api/user')
            ->assertStatus(403);
    }

    public function test_a_super_admin_can_reactivate_a_suspended_tenant(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
        $user = $this->makeTenantUser($this->tenant);

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('tenant.status', Tenant::STATUS_ACTIVE);

        $this->assertDatabaseHas('landlord_audit_logs', [
            'landlord_admin_id' => $this->superAdmin->id,
            'action' => 'tenant.reactivated',
            'subject_id' => $this->tenant->id,
        ]);

        // the same session works again once the tenant is active
        $this->actingAsTenantUser($user, $this->tenant)
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_reactivating_an_active_tenant_is_blocked(): void
    {
        $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/reactivate")
            ->assertStatus(422);
    }

    /**
     * Suspending via the endpoint (not a direct status update) must mark every
     * user's session invalidated, so reactivating doesn't silently hand an old
     * session access back without a fresh login.
     */
    public function test_reactivating_does_not_restore_a_session_that_predates_the_suspension(): void
    {
        $user = $this->makeTenantUser($this->tenant);
        $oldSession = ['session_issued_at' => now()->timestamp];

        $this->actingAsTenantUser($user, $this->tenant, $oldSession)->getJson('/api/user')->assertOk();

        $this->travel(1)->minutes();
        $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/suspend")
            ->assertOk();

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/reactivate")
            ->assertOk();

        // the pre-suspension session is still rejected even though the tenant is active again
        $this->actingAsTenantUser($user, $this->tenant, $oldSession)
            ->getJson('/api/user')
            ->assertStatus(401);

        // a fresh login (issued now) works fine
        $this->actingAsTenantUser($user, $this->tenant)
            ->getJson('/api/user')
            ->assertOk();
    }
}
