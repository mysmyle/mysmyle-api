<?php

namespace Tests\Feature;

use App\Models\Landlord\LandlordAdmin;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class LandlordAdminTierTest extends TestCase
{
    use BuildsTenantData;

    private $superAdmin;

    private $support;

    private $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->superAdmin = $this->makeLandlordAdmin(['email' => 'super@mysmyle.test']);
        $this->support = $this->makeLandlordAdmin(['email' => 'support@mysmyle.test', 'role' => LandlordAdmin::ROLE_SUPPORT]);
        $this->tenant = $this->makeTenant();
    }

    public function test_support_can_view_and_resend_but_not_create_a_tenant(): void
    {
        $this->actingAsLandlordAdmin($this->support)->getJson('/api/landlord/tenants')->assertOk();
        $this->actingAsLandlordAdmin($this->support)->getJson("/api/landlord/tenants/{$this->tenant->id}")->assertOk();

        $this->actingAsLandlordAdmin($this->support)
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/resend-setup-link")
            ->assertStatus(422); // no pending link — but not a 403, confirming the route is reachable

        $this->actingAsLandlordAdmin($this->support)
            ->postJson('/api/landlord/tenants', ['tenant_name' => 'X', 'admin_name' => 'X', 'admin_email' => 'x@x.test'])
            ->assertStatus(403);
    }

    public function test_support_cannot_suspend_or_reactivate_a_tenant(): void
    {
        $this->actingAsLandlordAdmin($this->support)
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/suspend")
            ->assertStatus(403);

        $this->actingAsLandlordAdmin($this->support)
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/reactivate")
            ->assertStatus(403);
    }

    public function test_support_can_view_audit_log_and_impersonate(): void
    {
        $this->actingAsLandlordAdmin($this->support)->getJson('/api/landlord/audit-logs')->assertOk();
        $this->actingAsLandlordAdmin($this->support)
            ->getJson("/api/landlord/tenants/{$this->tenant->id}/users")
            ->assertOk();

        $user = $this->makeTenantUser($this->tenant);

        $this->actingAsLandlordAdmin($this->support)
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/impersonate/{$user->id}", ['reason' => 'Support ticket'])
            ->assertOk();
    }

    public function test_support_cannot_manage_admins_or_settings(): void
    {
        $this->actingAsLandlordAdmin($this->support)->getJson('/api/landlord/admins')->assertStatus(403);
        $this->actingAsLandlordAdmin($this->support)
            ->postJson('/api/landlord/admins', ['name' => 'X', 'email' => 'x@x.test', 'role' => 'support'])
            ->assertStatus(403);
        $this->actingAsLandlordAdmin($this->support)
            ->getJson('/api/landlord/settings/password-policy')
            ->assertStatus(403);
    }

    public function test_a_super_admin_can_still_do_everything(): void
    {
        $this->actingAsLandlordAdmin($this->superAdmin)->getJson('/api/landlord/admins')->assertOk();
        $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/suspend")
            ->assertOk();
    }

    public function test_demoting_the_last_active_super_admin_is_blocked(): void
    {
        $this->actingAsLandlordAdmin($this->support)->getJson('/api/landlord/me')->assertOk(); // sanity: support exists

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->putJson("/api/landlord/admins/{$this->superAdmin->id}", [
                'name' => $this->superAdmin->name, 'email' => $this->superAdmin->email,
                'status' => 'active', 'role' => LandlordAdmin::ROLE_SUPPORT,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_demoting_is_allowed_when_another_super_admin_remains(): void
    {
        $other = $this->makeLandlordAdmin(['email' => 'other@mysmyle.test']);

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->putJson("/api/landlord/admins/{$other->id}", [
                'name' => $other->name, 'email' => $other->email,
                'status' => 'active', 'role' => LandlordAdmin::ROLE_SUPPORT,
            ])
            ->assertOk()
            ->assertJsonPath('admin.role', LandlordAdmin::ROLE_SUPPORT);
    }

    public function test_disabling_a_super_admin_who_is_not_the_last_one_is_allowed(): void
    {
        $secondSuperAdmin = $this->makeLandlordAdmin(['email' => 'second-super@mysmyle.test']);

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->putJson("/api/landlord/admins/{$secondSuperAdmin->id}", [
                'name' => $secondSuperAdmin->name, 'email' => $secondSuperAdmin->email,
                'status' => 'disabled', 'role' => LandlordAdmin::ROLE_SUPER_ADMIN,
            ])
            ->assertOk();
    }
}
