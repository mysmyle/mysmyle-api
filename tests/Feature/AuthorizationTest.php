<?php

namespace Tests\Feature;

use App\Models\Landlord\Tenant;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use BuildsTenantData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
    }

    public function test_control_panel_route_is_forbidden_without_the_cp_module(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeTenantUser($tenant);

        $this->actingAsTenantUser($user, $tenant)
            ->getJson('/api/users')
            ->assertStatus(403)
            ->assertJson(['message' => 'Access Denied.']);
    }

    public function test_control_panel_route_is_allowed_with_the_cp_module(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeTenantUser($tenant);
        $this->grantControlPanel($user);

        $this->actingAsTenantUser($user, $tenant)
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonStructure(['users']);
    }

    public function test_revoking_cp_access_forbids_the_route(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeTenantUser($tenant);
        $this->grantControlPanel($user);

        $user->moduleAccess()->update(['allowed' => false]);

        $this->actingAsTenantUser($user, $tenant)
            ->getJson('/api/users')
            ->assertStatus(403);
    }

    public function test_profile_endpoint_is_open_to_any_authenticated_tenant_user(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeTenantUser($tenant);

        $this->actingAsTenantUser($user, $tenant)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.can_control_panel', false);
    }

    public function test_tenant_routes_require_a_tenant_session(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->getJson('/api/user')
            ->assertStatus(403)
            ->assertJson(['message' => 'No tenant context.']);
    }

    public function test_inactive_tenant_user_is_unauthenticated(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeTenantUser($tenant);
        $user->update(['status' => 'inactive']);

        $this->actingAsTenantUser($user, $tenant)
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_suspended_tenant_cannot_be_resolved(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_SUSPENDED]);
        $user = $this->makeTenantUser($tenant);

        $this->actingAsTenantUser($user, $tenant)
            ->getJson('/api/user')
            ->assertStatus(403)
            ->assertJson(['message' => 'Tenant not available.']);
    }

    public function test_landlord_routes_require_a_landlord_session(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->getJson('/api/landlord/tenants')
            ->assertStatus(401);
    }

    public function test_landlord_admin_can_list_tenants(): void
    {
        $this->makeTenant();
        $admin = $this->makeLandlordAdmin();

        $this->actingAsLandlordAdmin($admin)
            ->getJson('/api/landlord/tenants')
            ->assertOk()
            ->assertJsonStructure(['tenants' => [['id', 'name', 'slug', 'status']]]);
    }

    public function test_disabled_landlord_admin_is_unauthenticated(): void
    {
        $admin = $this->makeLandlordAdmin(['status' => 'disabled']);

        $this->actingAsLandlordAdmin($admin)
            ->getJson('/api/landlord/tenants')
            ->assertStatus(401);
    }

    public function test_a_tenant_user_cannot_reach_landlord_routes(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeTenantUser($tenant);

        $this->actingAsTenantUser($user, $tenant)
            ->getJson('/api/landlord/tenants')
            ->assertStatus(401);
    }
}
