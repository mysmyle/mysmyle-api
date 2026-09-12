<?php

namespace Tests\Feature;

use App\Models\Landlord\AuditLog as LandlordAuditLog;
use App\Models\Tenant\AuditLog as TenantAuditLog;
use App\Models\Tenant\Department;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use BuildsTenantData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->seedDesignations();
    }

    public function test_creating_a_tenant_writes_a_landlord_audit_log_entry(): void
    {
        Queue::fake();
        $admin = $this->makeLandlordAdmin();

        $this->actingAsLandlordAdmin($admin)->postJson('/api/landlord/tenants', [
            'tenant_name' => 'New Clinic',
            'admin_name' => 'Dr Admin',
            'admin_email' => 'admin@new-clinic.test',
        ])->assertStatus(202);

        $this->assertDatabaseHas('landlord_audit_logs', [
            'landlord_admin_id' => $admin->id,
            'action' => 'tenant.created',
        ]);
    }

    public function test_landlord_audit_log_endpoint_requires_a_landlord_admin(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->getJson('/api/landlord/audit-logs')
            ->assertStatus(401);
    }

    public function test_landlord_admin_can_list_audit_log_entries(): void
    {
        $admin = $this->makeLandlordAdmin();
        LandlordAuditLog::record($admin->id, 'settings.password_policy_updated', null, null, ['min_length' => 12]);

        $response = $this->actingAsLandlordAdmin($admin)->getJson('/api/landlord/audit-logs')->assertOk();

        $response->assertJsonPath('data.0.action', 'settings.password_policy_updated');
        $response->assertJsonPath('data.0.admin.id', $admin->id);
    }

    public function test_creating_a_department_writes_a_tenant_audit_log_entry(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeTenantUser($tenant);
        $this->grantModule($user, 'CP');
        $this->grantCpPermission($user, 'CP.ROLES', 'add');

        $this->actingAsTenantUser($user, $tenant)
            ->postJson('/api/departments', ['name' => 'Radiology'])
            ->assertStatus(201);

        $department = Department::where('name', 'Radiology')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'department.created',
            'subject_id' => $department->id,
        ]);
    }

    public function test_audit_log_endpoint_is_gated_by_cp_audit_permission(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeTenantUser($tenant);
        $this->grantModule($user, 'CP');

        $this->actingAsTenantUser($user, $tenant)->getJson('/api/audit-logs')->assertStatus(403);

        $this->grantCpPermission($user, 'CP.AUDIT', 'view');

        TenantAuditLog::record($user->id, 'staff.created', null, null, ['name' => 'Dr Reyes']);

        $response = $this->actingAsTenantUser($user, $tenant)->getJson('/api/audit-logs')->assertOk();

        $response->assertJsonPath('data.0.action', 'staff.created');
        $response->assertJsonPath('data.0.user.id', $user->id);
    }
}
