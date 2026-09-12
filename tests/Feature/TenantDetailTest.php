<?php

namespace Tests\Feature;

use App\Models\Landlord\AuditLog;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantUser;
use App\Services\DepartmentService;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class TenantDetailTest extends TestCase
{
    use BuildsTenantData;

    private $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->seedDesignations();
        $this->superAdmin = $this->makeLandlordAdmin();
    }

    public function test_viewing_a_tenant_requires_a_landlord_admin(): void
    {
        $tenant = $this->makeTenant();

        $this->withHeader('Origin', 'http://localhost')
            ->getJson("/api/landlord/tenants/{$tenant->id}")
            ->assertStatus(401);
    }

    public function test_shows_stats_for_an_active_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->makeTenantUser($tenant, ['email' => 'admin@test-clinic.test']);
        $inactiveGuest = $this->makeGuestUser($tenant, 'Inactive Guest', ['email' => 'inactive@test-clinic.test', 'status' => 'inactive']);
        // the landlord-side login row is what active_users_count reads
        TenantUser::where('email', $inactiveGuest->email)->update(['status' => 'inactive']);
        app(DepartmentService::class)->createWithRoles('Reception');

        $response = $this->actingAsLandlordAdmin($this->superAdmin)
            ->getJson("/api/landlord/tenants/{$tenant->id}")
            ->assertOk();

        $response->assertJsonPath('tenant.name', $tenant->name);
        $response->assertJsonPath('tenant.total_users_count', 2);
        $response->assertJsonPath('tenant.active_users_count', 1);
        $response->assertJsonPath('tenant.staff_count', 1); // only makeTenantUser creates a Staff row
        $response->assertJsonPath('tenant.department_count', 1);
        $response->assertJsonPath('tenant.role_count', 4); // one role per seeded designation
    }

    public function test_stats_are_null_for_a_tenant_still_provisioning(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PROVISIONING]);

        $response = $this->actingAsLandlordAdmin($this->superAdmin)
            ->getJson("/api/landlord/tenants/{$tenant->id}")
            ->assertOk();

        $response->assertJsonPath('tenant.staff_count', null);
        $response->assertJsonPath('tenant.department_count', null);
    }

    public function test_stats_still_work_for_a_suspended_tenant(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_SUSPENDED]);
        $this->makeTenantUser($tenant, ['email' => 'admin@test-clinic.test']);

        $response = $this->actingAsLandlordAdmin($this->superAdmin)
            ->getJson("/api/landlord/tenants/{$tenant->id}")
            ->assertOk();

        $response->assertJsonPath('tenant.staff_count', 1);
    }

    public function test_the_audit_log_can_be_filtered_by_tenant(): void
    {
        $tenantA = $this->makeTenant(['slug' => 'clinic-a', 'db_name' => 'mysmyle_a']);
        $tenantB = $this->makeTenant(['slug' => 'clinic-b', 'db_name' => 'mysmyle_b']);

        AuditLog::record($this->superAdmin->id, 'tenant.suspended', Tenant::class, $tenantA->id, [], $tenantA->id);
        AuditLog::record($this->superAdmin->id, 'tenant.suspended', Tenant::class, $tenantB->id, [], $tenantB->id);

        $response = $this->actingAsLandlordAdmin($this->superAdmin)
            ->getJson("/api/landlord/audit-logs?tenant_id={$tenantA->id}")
            ->assertOk();

        $entries = collect($response->json('data'));
        $this->assertTrue($entries->every(fn ($e) => $e['tenant']['id'] === $tenantA->id));
        $this->assertCount(1, $entries);
    }
}
