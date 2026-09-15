<?php

namespace Tests\Feature;

use App\Mail\SetPasswordLinkMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\Landlord\Module;
use App\Models\Tenant\Role;
use App\Models\Tenant\RoleModuleAccess;
use App\Models\Tenant\Staff;
use App\Models\Tenant\User;
use App\Models\Tenant\UserModuleAccess;
use App\Services\DepartmentService;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class CreateUserAccountTest extends TestCase
{
    use BuildsTenantData;

    private $tenant;

    private $admin;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seedModules();
        $this->seedDesignations();

        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeTenantUser($this->tenant, ['email' => 'admin@test-clinic.test', 'name' => 'Admin']);
        $this->grantControlPanel($this->admin);

        // every account needs a role (department + designation) on create
        $department = app(DepartmentService::class)->createWithRoles('Front Office');
        $this->role = Role::where('department_id', $department->id)->firstOrFail();
    }

    private function submit(array $overrides = [])
    {
        return $this->actingAsTenantUser($this->admin, $this->tenant)->postJson('/api/users', array_merge([
            'type' => 'guest',
            'name' => 'Reception Desk',
            'email' => 'reception@test-clinic.test',
            'role_id' => $this->role->id,
        ], $overrides));
    }

    public function test_requires_control_panel_access(): void
    {
        $plain = $this->makeTenantUser($this->tenant, ['email' => 'plain@test-clinic.test', 'name' => 'Plain']);

        $this->actingAsTenantUser($plain, $this->tenant)
            ->postJson('/api/users', ['type' => 'guest', 'name' => 'x', 'email' => 'x@x.test', 'role_id' => $this->role->id])
            ->assertStatus(403);
    }

    public function test_creates_a_guest_account(): void
    {
        $this->submit(['status' => 'inactive']) // status is ignored on create
            ->assertStatus(201)
            ->assertJsonPath('user.display_name', 'Reception Desk')
            ->assertJsonPath('user.staff', null)
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.must_change_password', true);

        $user = User::where('email', 'reception@test-clinic.test')->first();
        $this->assertNull($user->staff_id);
        $this->assertSame('active', $user->status);
        $this->assertSame('Reception Desk', $user->name);
        $this->assertTrue($user->must_change_password);
        $this->assertDatabaseHas('tenant_users', [
            'email' => 'reception@test-clinic.test', 'tenant_id' => $this->tenant->id,
        ]);
    }


    public function test_a_temporary_password_is_generated_revealed_and_emailed(): void
    {
        $response = $this->submit()->assertStatus(201);

        $temp = $response->json('temporary_password');
        $this->assertIsString($temp);
        $this->assertSame([], PasswordPolicy::current()->violations($temp));

        $user = User::where('email', 'reception@test-clinic.test')->first();
        $this->assertTrue(Hash::check($temp, $user->password));

        Mail::assertQueued(
            TemporaryPasswordMail::class,
            fn (TemporaryPasswordMail $mail) => $mail->hasTo('reception@test-clinic.test')
                && $mail->temporaryPassword === $temp,
        );
    }

    private function submitStaff(int $staffId, array $overrides = [])
    {
        return $this->submit(array_merge([
            'type' => 'staff', 'staff_id' => $staffId, 'name' => null, 'email' => 'staffuser@test-clinic.test',
        ], $overrides));
    }

    public function test_creates_a_staff_linked_account_with_no_password(): void
    {
        $staff = Staff::create(['name' => 'Dr Cruz', 'personal_email' => 'cruz.personal@example.com', 'status' => 'active']);

        $this->submitStaff($staff->id, ['email' => 'cruz.login@test-clinic.test'])
            ->assertStatus(201)
            ->assertJsonPath('user.display_name', 'Dr Cruz')
            ->assertJsonPath('user.staff.id', $staff->id)
            ->assertJsonPath('user.must_change_password', false)
            ->assertJsonPath('temporary_password', null)
            ->assertJsonPath('setup_email', 'cruz.personal@example.com');

        $user = User::where('email', 'cruz.login@test-clinic.test')->first();
        $this->assertNull($user->password);
        $this->assertFalse($user->must_change_password);
    }

    public function test_a_staff_account_gets_a_set_password_link_to_the_personal_email(): void
    {
        $staff = Staff::create(['name' => 'Dr Vega', 'personal_email' => 'vega.personal@example.com', 'status' => 'active']);

        $this->submitStaff($staff->id, ['email' => 'vega.login@test-clinic.test'])->assertStatus(201);

        // token keyed to the LOGIN email
        $this->assertDatabaseHas('password_setup_tokens', [
            'tenant_id' => $this->tenant->id, 'email' => 'vega.login@test-clinic.test', 'used_at' => null,
        ]);

        // link emailed to the PERSONAL email
        Mail::assertQueued(
            SetPasswordLinkMail::class,
            fn (SetPasswordLinkMail $mail) => $mail->hasTo('vega.personal@example.com'),
        );
        Mail::assertNotQueued(TemporaryPasswordMail::class);
    }

    public function test_rejects_a_staff_account_when_the_staff_has_no_personal_email(): void
    {
        $staff = Staff::create(['name' => 'Dr NoEmail', 'status' => 'active']); // bypasses form validation

        $this->submitStaff($staff->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('staff_id');

        $this->assertDatabaseMissing('users', ['email' => 'staffuser@test-clinic.test']);
    }

    public function test_assigns_a_role_and_snapshots_its_permissions(): void
    {
        $department = app(DepartmentService::class)->createWithRoles('Reception');
        $role = Role::where('department_id', $department->id)->firstOrFail();
        $cpModuleId = Module::where('abbreviation', 'CP')->value('id');
        RoleModuleAccess::create(['role_id' => $role->id, 'module_id' => $cpModuleId, 'allowed' => true]);

        $this->submit(['role_id' => $role->id])->assertStatus(201);

        $user = User::where('email', 'reception@test-clinic.test')->first();
        $this->assertSame($role->id, $user->role_id);
        $this->assertTrue(
            UserModuleAccess::where('user_id', $user->id)->where('module_id', $cpModuleId)->value('allowed'),
        );
    }

    public function test_rejects_a_duplicate_email(): void
    {
        // admin@test-clinic.test already exists in tenant_users (from setUp)
        $this->submit(['email' => 'admin@test-clinic.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['name' => 'Reception Desk']);
    }

    public function test_rejects_a_guest_without_a_name(): void
    {
        $this->submit(['name' => null])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_rejects_a_staff_account_without_a_valid_staff_id(): void
    {
        $this->submit(['type' => 'staff', 'staff_id' => 999, 'name' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('staff_id');
    }

    public function test_rejects_an_account_without_a_role(): void
    {
        $this->submit(['role_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');

        $this->assertDatabaseMissing('users', ['name' => 'Reception Desk']);
    }

    public function test_staff_list_is_available_to_control_panel(): void
    {
        Staff::create(['name' => 'Zaira', 'status' => 'active']);
        Staff::create(['name' => 'Aaron', 'status' => 'active']);

        $names = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->getJson('/api/staff')
            ->assertOk()
            ->json('staff.*.name');

        $this->assertContains('Aaron', $names);
        $this->assertContains('Zaira', $names);
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names); // ordered by name
        $this->assertLessThan(array_search('Zaira', $names, true), array_search('Aaron', $names, true));
    }
}
