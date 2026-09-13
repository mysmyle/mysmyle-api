<?php

namespace Tests\Feature;

use App\Mail\SetPasswordLinkMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\Landlord\Module;
use App\Models\Landlord\TenantUser;
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

class EditUserAccountTest extends TestCase
{
    use BuildsTenantData;

    private $tenant;

    private $admin;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->seedDesignations();

        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeTenantUser($this->tenant, ['email' => 'admin@test-clinic.test', 'name' => 'Admin']);
        $this->grantControlPanel($this->admin);

        $this->account = $this->makeGuestUser($this->tenant, 'Front Desk', ['email' => 'frontdesk@test-clinic.test']);
    }

    private function save(array $overrides = [])
    {
        return $this->actingAsTenantUser($this->admin, $this->tenant)->putJson("/api/users/{$this->account->id}", array_merge([
            'name' => 'Front Desk',
            'email' => 'frontdesk@test-clinic.test',
            'status' => 'active',
            'role_id' => null,
        ], $overrides));
    }

    public function test_requires_control_panel_access(): void
    {
        $plain = $this->makeGuestUser($this->tenant, 'Plain', ['email' => 'plain@test-clinic.test']);

        $this->actingAsTenantUser($plain, $this->tenant)
            ->putJson("/api/users/{$this->account->id}", ['name' => 'x', 'email' => 'x@x.test', 'status' => 'active', 'role_id' => null])
            ->assertStatus(403);
    }

    public function test_updates_email_and_status_and_syncs_the_login_row(): void
    {
        $this->save(['email' => 'reception@test-clinic.test', 'status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('user.email', 'reception@test-clinic.test')
            ->assertJsonPath('user.status', 'inactive');

        $this->assertDatabaseHas('users', ['id' => $this->account->id, 'email' => 'reception@test-clinic.test', 'status' => 'inactive']);
        // the landlord-side login row follows
        $this->assertDatabaseHas('tenant_users', [
            'email' => 'reception@test-clinic.test', 'tenant_id' => $this->tenant->id, 'status' => 'inactive',
        ]);
        $this->assertDatabaseMissing('tenant_users', ['email' => 'frontdesk@test-clinic.test']);
    }

    public function test_disabling_an_account_records_who_and_when(): void
    {
        $this->save(['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('user.disabled_by', 'Admin');

        $fresh = $this->account->fresh();
        $this->assertSame($this->admin->id, $fresh->disabled_by);
        $this->assertNotNull($fresh->disabled_at);
    }

    public function test_reactivating_an_account_clears_the_disabled_tracking(): void
    {
        $this->save(['status' => 'inactive'])->assertOk();

        $this->save(['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('user.disabled_by', null);

        $fresh = $this->account->fresh();
        $this->assertNull($fresh->disabled_by);
        $this->assertNull($fresh->disabled_at);
    }

    public function test_saving_without_changing_status_does_not_touch_disabled_tracking(): void
    {
        $this->save(['status' => 'inactive'])->assertOk();

        $this->save(['status' => 'inactive', 'email' => 'reception2@test-clinic.test'])->assertOk();

        $this->assertSame($this->admin->id, $this->account->fresh()->disabled_by);
    }

    public function test_a_deactivated_account_cannot_log_in(): void
    {
        $this->save(['status' => 'inactive'])->assertOk();

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', ['email' => 'frontdesk@test-clinic.test', 'password' => 'password'])
            ->assertStatus(422);
    }

    public function test_the_password_cannot_be_changed_here(): void
    {
        $original = $this->account->password;

        $this->save(['password' => 'brand-new-secret'])->assertOk();

        $this->assertSame($original, $this->account->fresh()->password);
    }

    private function resetPassword()
    {
        return $this->actingAsTenantUser($this->admin, $this->tenant)
            ->postJson("/api/users/{$this->account->id}/password/reset");
    }

    public function test_resetting_a_guest_account_generates_a_temporary_password(): void
    {
        Mail::fake();

        $response = $this->resetPassword()->assertOk(); // $this->account is a guest

        $temp = $response->json('temporary_password');
        $this->assertIsString($temp);
        $this->assertSame([], PasswordPolicy::current()->violations($temp));

        $fresh = $this->account->fresh();
        $this->assertTrue(Hash::check($temp, $fresh->password));
        $this->assertTrue($fresh->must_change_password);

        Mail::assertQueued(
            TemporaryPasswordMail::class,
            fn (TemporaryPasswordMail $mail) => $mail->hasTo('frontdesk@test-clinic.test')
                && $mail->temporaryPassword === $temp,
        );
    }

    public function test_resetting_a_staff_account_emails_a_set_password_link_to_the_personal_email(): void
    {
        Mail::fake();

        $staff = Staff::create(['name' => 'Dr Cruz', 'personal_email' => 'cruz.personal@example.com', 'status' => 'active']);
        $user = User::create([
            'staff_id' => $staff->id, 'email' => 'cruz.login@test-clinic.test',
            'password' => Hash::make('old-password'), 'status' => 'active',
        ]);
        TenantUser::create(['email' => 'cruz.login@test-clinic.test', 'tenant_id' => $this->tenant->id, 'status' => 'active']);

        $this->actingAsTenantUser($this->admin, $this->tenant)
            ->postJson("/api/users/{$user->id}/password/reset")
            ->assertOk()
            ->assertJsonPath('setup_email', 'cruz.personal@example.com')
            ->assertJsonPath('temporary_password', null);

        $fresh = $user->fresh();
        $this->assertNull($fresh->password); // old password no longer works
        $this->assertFalse($fresh->must_change_password);

        $this->assertDatabaseHas('password_setup_tokens', [
            'tenant_id' => $this->tenant->id, 'email' => 'cruz.login@test-clinic.test', 'used_at' => null,
        ]);
        Mail::assertQueued(
            SetPasswordLinkMail::class,
            fn (SetPasswordLinkMail $mail) => $mail->hasTo('cruz.personal@example.com'),
        );
    }

    public function test_password_reset_requires_control_panel_access(): void
    {
        $plain = $this->makeGuestUser($this->tenant, 'Plain2', ['email' => 'plain2@test-clinic.test']);

        $this->actingAsTenantUser($plain, $this->tenant)
            ->postJson("/api/users/{$this->account->id}/password/reset")
            ->assertStatus(403);
    }

    public function test_assigning_a_role_snapshots_its_template(): void
    {
        $department = app(DepartmentService::class)->createWithRoles('Reception');
        $role = Role::where('department_id', $department->id)->firstOrFail();
        $cpModuleId = Module::where('abbreviation', 'CP')->value('id');
        RoleModuleAccess::create(['role_id' => $role->id, 'module_id' => $cpModuleId, 'allowed' => true]);

        $this->save(['role_id' => $role->id])->assertOk();

        $this->assertSame($role->id, $this->account->fresh()->role_id);
        $this->assertTrue(
            UserModuleAccess::where('user_id', $this->account->id)->where('module_id', $cpModuleId)->value('allowed'),
        );
    }

    public function test_clearing_the_role(): void
    {
        $department = app(DepartmentService::class)->createWithRoles('Reception');
        $role = Role::where('department_id', $department->id)->firstOrFail();
        $this->save(['role_id' => $role->id])->assertOk();

        $this->save(['role_id' => null])->assertOk();

        $this->assertNull($this->account->fresh()->role_id);
    }

    public function test_email_must_stay_unique(): void
    {
        $this->makeGuestUser($this->tenant, 'Other', ['email' => 'taken@test-clinic.test']);

        $this->save(['email' => 'taken@test-clinic.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_keeping_the_same_email_is_allowed(): void
    {
        $this->save(['email' => 'frontdesk@test-clinic.test', 'status' => 'inactive'])->assertOk();
    }

    public function test_a_guest_name_is_required(): void
    {
        $this->save(['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_staff_account_name_comes_from_the_staff_record(): void
    {
        $staff = Staff::create(['name' => 'Dr Vega', 'status' => 'active']);
        $staffUser = User::create([
            'staff_id' => $staff->id, 'email' => 'vega@test-clinic.test',
            'password' => Hash::make('password'), 'status' => 'active',
        ]);
        TenantUser::create(['email' => 'vega@test-clinic.test', 'tenant_id' => $this->tenant->id, 'status' => 'active']);

        $this->actingAsTenantUser($this->admin, $this->tenant)
            ->putJson("/api/users/{$staffUser->id}", [
                'name' => 'Attempted Override', 'email' => 'vega@test-clinic.test', 'status' => 'active', 'role_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('user.display_name', 'Dr Vega');

        $this->assertNull($staffUser->fresh()->name);
    }
}
