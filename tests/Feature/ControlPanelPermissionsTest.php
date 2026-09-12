<?php

namespace Tests\Feature;

use App\Models\Landlord\Module;
use App\Models\Landlord\Permission;
use App\Models\Tenant\Role;
use App\Models\Tenant\RoleModuleAccess;
use App\Models\Tenant\Staff;
use App\Models\Tenant\UserHasPermission;
use App\Services\DepartmentService;
use App\Support\TenantCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class ControlPanelPermissionsTest extends TestCase
{
    use BuildsTenantData;

    private $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->seedDesignations();
        $this->tenant = $this->makeTenant();
    }

    /** A user with CP module access but no section permissions is still locked out of every section. */
    public function test_cp_module_access_alone_grants_no_sections(): void
    {
        $user = $this->makeTenantUser($this->tenant, ['email' => 'bare@test-clinic.test']);
        $this->grantModule($user, 'CP');

        foreach (['/api/staff', '/api/users', '/api/roles', '/api/designations'] as $url) {
            $this->actingAsTenantUser($user, $this->tenant)->getJson($url)->assertStatus(403);
        }
    }

    public function test_staff_view_lists_but_does_not_allow_create_or_edit(): void
    {
        $user = $this->makeTenantUser($this->tenant, ['email' => 'staffviewer@test-clinic.test']);
        $this->grantModule($user, 'CP');
        $this->grantCpPermission($user, 'CP.STAFF', 'view');

        $this->actingAsTenantUser($user, $this->tenant)->getJson('/api/staff')->assertOk();

        $this->actingAsTenantUser($user, $this->tenant)
            ->postJson('/api/staff', ['name' => 'New Person'])
            ->assertStatus(403);
    }

    public function test_staff_add_and_edit_permissions_gate_writes(): void
    {
        $user = $this->makeTenantUser($this->tenant, ['email' => 'staffmgr@test-clinic.test']);
        $this->grantModule($user, 'CP');
        $this->grantCpPermission($user, 'CP.STAFF', 'add');
        $this->grantCpPermission($user, 'CP.STAFF', 'edit');

        $this->actingAsTenantUser($user, $this->tenant)
            ->postJson('/api/staff', ['name' => 'Dr Reyes', 'personal_email' => 'reyes@example.com'])
            ->assertStatus(201);

        $staff = Staff::where('name', 'Dr Reyes')->firstOrFail();

        $this->actingAsTenantUser($user, $this->tenant)
            ->putJson("/api/staff/{$staff->id}", ['name' => 'Dr Reyes', 'personal_email' => 'reyes@example.com', 'status' => 'inactive'])
            ->assertOk();
    }

    /** The Staff section and the User Accounts section are independent grants. */
    public function test_staff_section_does_not_open_the_users_section(): void
    {
        $user = $this->makeTenantUser($this->tenant, ['email' => 'onlystaff@test-clinic.test']);
        $this->grantModule($user, 'CP');
        foreach (['view', 'add', 'edit'] as $action) {
            $this->grantCpPermission($user, 'CP.STAFF', $action);
        }

        $this->actingAsTenantUser($user, $this->tenant)->getJson('/api/users')->assertStatus(403);
    }

    public function test_roles_edit_gates_the_permission_matrix_and_resync(): void
    {
        $department = app(DepartmentService::class)->createWithRoles('Reception');
        $role = Role::where('department_id', $department->id)->firstOrFail();
        $cpModuleId = Module::controlPanelId();

        $user = $this->makeTenantUser($this->tenant, ['email' => 'roleviewer@test-clinic.test']);
        $this->grantModule($user, 'CP');
        $this->grantCpPermission($user, 'CP.ROLES', 'view');

        $this->actingAsTenantUser($user, $this->tenant)->getJson('/api/roles')->assertOk();

        $this->actingAsTenantUser($user, $this->tenant)
            ->putJson("/api/roles/{$role->id}/modules/{$cpModuleId}/access", ['allowed' => true])
            ->assertStatus(403);

        $this->grantCpPermission($user, 'CP.ROLES', 'edit');

        $this->actingAsTenantUser($user, $this->tenant)
            ->putJson("/api/roles/{$role->id}/modules/{$cpModuleId}/access", ['allowed' => true])
            ->assertOk();
    }

    /**
     * The cached permission-code set must be a plain array. A Collection (or any
     * object) survives the array cache used in tests but comes back as
     * __PHP_Incomplete_Class through Redis/igbinary in production.
     */
    public function test_cached_permission_codes_are_a_plain_array(): void
    {
        $user = $this->makeTenantUser($this->tenant, ['email' => 'cachecheck@test-clinic.test']);
        $this->grantModule($user, 'CP');
        $this->grantCpPermission($user, 'CP.STAFF', 'view');

        $this->actingAsTenantUser($user, $this->tenant)->getJson('/api/staff')->assertOk();

        $cached = Cache::get(TenantCache::userPermissionsKey($this->tenant->id, $user->id));
        $this->assertIsArray($cached);
        $this->assertContains('CP.STAFF:view', $cached);
    }

    public function test_catalog_view_gates_designations_and_stations(): void
    {
        $user = $this->makeTenantUser($this->tenant, ['email' => 'catalog@test-clinic.test']);
        $this->grantModule($user, 'CP');

        $this->actingAsTenantUser($user, $this->tenant)->getJson('/api/designations')->assertStatus(403);

        $this->grantCpPermission($user, 'CP.CATALOG', 'view');

        $this->actingAsTenantUser($user, $this->tenant)->getJson('/api/designations')->assertOk();
    }

    public function test_backfill_command_grants_cp_permissions_to_existing_admins_and_role_templates(): void
    {
        // a role that has CP module access but predates the CP.* permissions
        $department = app(DepartmentService::class)->createWithRoles('Administration');
        $role = Role::where('department_id', $department->id)->firstOrFail();
        $cpModuleId = Module::controlPanelId();
        RoleModuleAccess::create(['role_id' => $role->id, 'module_id' => $cpModuleId, 'allowed' => true]);

        // an admin with CP module access only (no permission rows)
        $admin = $this->makeTenantUser($this->tenant, ['email' => 'legacyadmin@test-clinic.test']);
        $this->grantModule($admin, 'CP');

        // locked out beforehand
        $this->actingAsTenantUser($admin, $this->tenant)->getJson('/api/staff')->assertStatus(403);

        Artisan::call('cp:backfill-permissions');

        // the role template now carries the CP permissions
        $this->assertGreaterThan(0, UserHasPermission::where('user_id', $admin->id)->count());
        $this->assertGreaterThan(0, $role->permissions()->count());

        // and the admin can now use the Control Panel
        $this->actingAsTenantUser($admin, $this->tenant)->getJson('/api/staff')->assertOk();
    }

    /**
     * Toggling a *system* module carries its station permissions with it — for
     * a role template and (with cache invalidation) for a user.
     */
    public function test_allowing_the_cp_module_for_a_role_grants_its_stations(): void
    {
        $department = app(DepartmentService::class)->createWithRoles('Reception');
        $role = Role::where('department_id', $department->id)->firstOrFail();
        $cpModuleId = Module::controlPanelId();
        $cpPermissionCount = Permission::whereHas('station', fn ($q) => $q->where('module_id', $cpModuleId))->count();

        $admin = $this->makeTenantUser($this->tenant, ['email' => 'rolemgr@test-clinic.test']);
        $this->grantModule($admin, 'CP');
        $this->grantCpPermission($admin, 'CP.ROLES', 'edit');

        $this->actingAsTenantUser($admin, $this->tenant)
            ->putJson("/api/roles/{$role->id}/modules/{$cpModuleId}/access", ['allowed' => true])
            ->assertOk();

        $this->assertSame($cpPermissionCount, $role->permissions()->count());

        // ...and denying it takes them away
        $this->actingAsTenantUser($admin, $this->tenant)
            ->putJson("/api/roles/{$role->id}/modules/{$cpModuleId}/access", ['allowed' => false])
            ->assertOk();

        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_allowing_the_cp_module_for_a_user_grants_stations_and_unlocks_the_cp(): void
    {
        $cpModuleId = Module::controlPanelId();

        $admin = $this->makeTenantUser($this->tenant, ['email' => 'usermgr@test-clinic.test']);
        $this->grantControlPanel($admin);

        $target = $this->makeTenantUser($this->tenant, ['email' => 'promote-me@test-clinic.test']);

        $this->actingAsTenantUser($target, $this->tenant)->getJson('/api/staff')->assertStatus(403);

        $this->actingAsTenantUser($admin, $this->tenant)
            ->putJson("/api/users/{$target->id}/modules/{$cpModuleId}/access", ['allowed' => true])
            ->assertOk();

        $this->assertGreaterThan(0, UserHasPermission::where('user_id', $target->id)->count());
        $this->actingAsTenantUser($target, $this->tenant)->getJson('/api/staff')->assertOk();
    }

    public function test_toggling_a_clinical_module_does_not_touch_station_permissions(): void
    {
        $department = app(DepartmentService::class)->createWithRoles('Reception');
        $role = Role::where('department_id', $department->id)->firstOrFail();
        $rapModuleId = Module::where('abbreviation', 'RAP')->value('id');

        $admin = $this->makeTenantUser($this->tenant, ['email' => 'clinicaltoggle@test-clinic.test']);
        $this->grantModule($admin, 'CP');
        $this->grantCpPermission($admin, 'CP.ROLES', 'edit');

        $this->actingAsTenantUser($admin, $this->tenant)
            ->putJson("/api/roles/{$role->id}/modules/{$rapModuleId}/access", ['allowed' => true])
            ->assertOk();

        $this->assertSame(0, $role->permissions()->count());
    }
}
