<?php

namespace Tests\Feature;

use App\Models\Tenant\Role;
use App\Services\DepartmentService;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class DepartmentRoleManagementTest extends TestCase
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

    private function makeEditor(string $email = 'editor@test-clinic.test')
    {
        $user = $this->makeTenantUser($this->tenant, ['email' => $email]);
        $this->grantModule($user, 'CP');
        $this->grantCpPermission($user, 'CP.ROLES', 'edit');

        return $user;
    }

    public function test_renaming_a_department_requires_edit_permission(): void
    {
        $department = app(DepartmentService::class)->createWithRoles('Reception');
        $user = $this->makeTenantUser($this->tenant, ['email' => 'viewer@test-clinic.test']);
        $this->grantModule($user, 'CP');
        $this->grantCpPermission($user, 'CP.ROLES', 'view');

        $this->actingAsTenantUser($user, $this->tenant)
            ->putJson("/api/departments/{$department->id}", ['name' => 'Front Desk'])
            ->assertStatus(403);
    }

    public function test_a_department_can_be_renamed(): void
    {
        $department = app(DepartmentService::class)->createWithRoles('Reception');
        $user = $this->makeEditor();

        $this->actingAsTenantUser($user, $this->tenant)
            ->putJson("/api/departments/{$department->id}", ['name' => 'Front Desk'])
            ->assertOk()
            ->assertJsonPath('department.name', 'Front Desk');

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'Front Desk']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'department.updated',
            'subject_id' => $department->id,
        ]);
    }

    public function test_renaming_a_department_enforces_a_unique_name(): void
    {
        app(DepartmentService::class)->createWithRoles('Reception');
        $lab = app(DepartmentService::class)->createWithRoles('Lab');
        $user = $this->makeEditor();

        $this->actingAsTenantUser($user, $this->tenant)
            ->putJson("/api/departments/{$lab->id}", ['name' => 'Reception'])
            ->assertStatus(422);
    }

    public function test_a_department_with_no_assigned_users_can_be_deleted(): void
    {
        $department = app(DepartmentService::class)->createWithRoles('Reception');
        $user = $this->makeEditor();

        $this->actingAsTenantUser($user, $this->tenant)
            ->deleteJson("/api/departments/{$department->id}")
            ->assertOk();

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
        $this->assertDatabaseMissing('roles', ['department_id' => $department->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'department.deleted',
            'subject_id' => $department->id,
        ]);
    }

    public function test_deleting_a_department_is_blocked_while_one_of_its_roles_has_a_user(): void
    {
        $department = app(DepartmentService::class)->createWithRoles('Reception');
        $role = Role::where('department_id', $department->id)->firstOrFail();
        $editor = $this->makeEditor();
        $this->makeTenantUser($this->tenant, ['email' => 'holder@test-clinic.test', 'role_id' => $role->id]);

        $this->actingAsTenantUser($editor, $this->tenant)
            ->deleteJson("/api/departments/{$department->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    public function test_an_unassigned_role_can_be_deleted(): void
    {
        $department = app(DepartmentService::class)->createWithRoles('Reception');
        $role = Role::where('department_id', $department->id)->firstOrFail();
        $user = $this->makeEditor();

        $this->actingAsTenantUser($user, $this->tenant)
            ->deleteJson("/api/roles/{$role->id}")
            ->assertOk();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'role.deleted',
            'subject_id' => $role->id,
        ]);
    }

    public function test_deleting_a_role_is_blocked_while_a_user_holds_it(): void
    {
        $department = app(DepartmentService::class)->createWithRoles('Reception');
        $role = Role::where('department_id', $department->id)->firstOrFail();
        $editor = $this->makeEditor();
        $this->makeTenantUser($this->tenant, ['email' => 'holder2@test-clinic.test', 'role_id' => $role->id]);

        $this->actingAsTenantUser($editor, $this->tenant)
            ->deleteJson("/api/roles/{$role->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }
}
