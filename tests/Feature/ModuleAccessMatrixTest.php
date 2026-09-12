<?php

namespace Tests\Feature;

use App\Models\Landlord\Module;
use App\Models\Tenant\Role;
use App\Models\Tenant\RoleModuleAccess;
use App\Services\DepartmentService;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

/**
 * GET /api/roles eager-loads module_access so the frontend can render a
 * roles-by-modules matrix without a request per role.
 */
class ModuleAccessMatrixTest extends TestCase
{
    use BuildsTenantData;

    public function test_role_listing_includes_each_roles_module_access(): void
    {
        $this->seedModules();
        $this->seedDesignations();
        $tenant = $this->makeTenant();
        $department = app(DepartmentService::class)->createWithRoles('Reception');
        $role = Role::where('department_id', $department->id)->firstOrFail();
        $rapModuleId = Module::where('abbreviation', 'RAP')->value('id');
        RoleModuleAccess::create(['role_id' => $role->id, 'module_id' => $rapModuleId, 'allowed' => true]);

        $user = $this->makeTenantUser($tenant, ['email' => 'viewer@test-clinic.test']);
        $this->grantModule($user, 'CP');
        $this->grantCpPermission($user, 'CP.ROLES', 'view');

        $response = $this->actingAsTenantUser($user, $tenant)->getJson('/api/roles')->assertOk();

        $roles = collect($response->json('roles'));
        $matched = $roles->firstWhere('id', $role->id);

        $this->assertNotNull($matched);
        $moduleAccess = collect($matched['module_access']);
        $this->assertTrue($moduleAccess->firstWhere('module_id', $rapModuleId)['allowed']);
    }
}
