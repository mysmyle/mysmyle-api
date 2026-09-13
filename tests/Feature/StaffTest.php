<?php

namespace Tests\Feature;

use App\Models\Tenant\Staff;
use App\Models\Tenant\User;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use BuildsTenantData;

    private $tenant;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();

        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeTenantUser($this->tenant, ['email' => 'admin@test-clinic.test', 'name' => 'Admin']);
        $this->grantControlPanel($this->admin);
    }

    private function acting()
    {
        return $this->actingAsTenantUser($this->admin, $this->tenant);
    }

    public function test_creating_staff_requires_control_panel_access(): void
    {
        $plain = $this->makeGuestUser($this->tenant, 'Plain', ['email' => 'plain@test-clinic.test']);

        $this->actingAsTenantUser($plain, $this->tenant)
            ->postJson('/api/staff', ['name' => 'Nope'])
            ->assertStatus(403);
    }

    public function test_creates_a_staff_member_with_the_required_fields(): void
    {
        $this->acting()
            ->postJson('/api/staff', ['name' => 'Dr Santos', 'personal_email' => 'dr.santos@example.com'])
            ->assertStatus(201)
            ->assertJsonPath('staff.name', 'Dr Santos')
            ->assertJsonPath('staff.personal_email', 'dr.santos@example.com')
            ->assertJsonPath('staff.status', 'active');

        $this->assertDatabaseHas('staff', [
            'name' => 'Dr Santos', 'personal_email' => 'dr.santos@example.com',
            'gender' => null, 'date_of_birth' => null, 'status' => 'active',
        ]);
    }

    public function test_personal_email_is_required(): void
    {
        $this->acting()->postJson('/api/staff', ['name' => 'No Email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('personal_email');
    }

    public function test_creates_a_staff_member_with_all_fields(): void
    {
        $this->acting()
            ->postJson('/api/staff', [
                'name' => 'Nurse Lim',
                'personal_email' => 'nurse.lim@example.com',
                'gender' => 'Female',
                'date_of_birth' => '1990-04-15',
            ])
            ->assertStatus(201)
            ->assertJsonPath('staff.personal_email', 'nurse.lim@example.com');

        $staff = Staff::where('name', 'Nurse Lim')->first();
        $this->assertSame('nurse.lim@example.com', $staff->personal_email);
        $this->assertSame('Female', $staff->gender);
        $this->assertSame('1990-04-15', $staff->date_of_birth->toDateString());
    }

    public function test_personal_email_must_be_a_valid_email(): void
    {
        $this->acting()
            ->postJson('/api/staff', ['name' => 'X', 'personal_email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('personal_email');
    }

    public function test_new_staff_are_always_active_even_if_a_status_is_submitted(): void
    {
        $this->acting()
            ->postJson('/api/staff', ['name' => 'Ignored Status', 'personal_email' => 'ignored@example.com', 'status' => 'inactive'])
            ->assertStatus(201)
            ->assertJsonPath('staff.status', 'active');

        $this->assertSame('active', Staff::where('name', 'Ignored Status')->first()->status);
    }

    public function test_records_who_created_the_staff_member_and_when(): void
    {
        $this->acting()
            ->postJson('/api/staff', ['name' => 'Dr Cruz', 'personal_email' => 'dr.cruz@example.com'])
            ->assertStatus(201)
            ->assertJsonPath('staff.created_by', 'Admin');

        $staff = Staff::where('name', 'Dr Cruz')->first();
        $this->assertSame($this->admin->id, $staff->created_by);
        $this->assertNotNull($staff->created_at);
    }

    public function test_name_is_required(): void
    {
        $this->acting()->postJson('/api/staff', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_date_of_birth_must_be_in_the_past(): void
    {
        $this->acting()->postJson('/api/staff', [
            'name' => 'X', 'personal_email' => 'x@example.com', 'date_of_birth' => now()->addDay()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_of_birth');
    }

    public function test_show_returns_a_staff_member(): void
    {
        $staff = Staff::create(['name' => 'Dr Reyes', 'gender' => 'Male', 'status' => 'active']);

        $this->acting()->getJson("/api/staff/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('staff.id', $staff->id)
            ->assertJsonPath('staff.name', 'Dr Reyes')
            ->assertJsonPath('staff.gender', 'Male');
    }

    public function test_updates_a_staff_member(): void
    {
        $staff = Staff::create(['name' => 'Old Name', 'gender' => 'Male', 'status' => 'active']);

        $this->acting()->putJson("/api/staff/{$staff->id}", [
            'name' => 'New Name',
            'personal_email' => 'new.name@example.com',
            'gender' => 'Female',
            'date_of_birth' => '1985-06-01',
            'status' => 'inactive',
        ])->assertOk()->assertJsonPath('staff.name', 'New Name');

        $staff->refresh();
        $this->assertSame('New Name', $staff->name);
        $this->assertSame('new.name@example.com', $staff->personal_email);
        $this->assertSame('Female', $staff->gender);
        $this->assertSame('1985-06-01', $staff->date_of_birth->toDateString());
        $this->assertSame('inactive', $staff->status);
    }

    public function test_disabling_a_staff_member_records_who_and_when(): void
    {
        $staff = Staff::create(['name' => 'Active One', 'status' => 'active']);

        $this->acting()->putJson("/api/staff/{$staff->id}", [
            'name' => 'Active One', 'personal_email' => 'active@example.com', 'status' => 'inactive',
        ])
            ->assertOk()
            ->assertJsonPath('staff.disabled_by', 'Admin');

        $fresh = $staff->fresh();
        $this->assertSame($this->admin->id, $fresh->disabled_by);
        $this->assertNotNull($fresh->disabled_at);
    }

    public function test_reactivating_a_staff_member_clears_the_disabled_tracking(): void
    {
        $staff = Staff::create(['name' => 'Toggled', 'status' => 'active']);

        $this->acting()->putJson("/api/staff/{$staff->id}", [
            'name' => 'Toggled', 'personal_email' => 'toggled@example.com', 'status' => 'inactive',
        ])->assertOk();

        $this->acting()->putJson("/api/staff/{$staff->id}", [
            'name' => 'Toggled', 'personal_email' => 'toggled@example.com', 'status' => 'active',
        ])
            ->assertOk()
            ->assertJsonPath('staff.disabled_by', null);

        $fresh = $staff->fresh();
        $this->assertNull($fresh->disabled_by);
        $this->assertNull($fresh->disabled_at);
    }

    public function test_saving_without_changing_status_does_not_touch_disabled_tracking(): void
    {
        $staff = Staff::create(['name' => 'Stable', 'status' => 'active']);

        $this->acting()->putJson("/api/staff/{$staff->id}", [
            'name' => 'Stable', 'personal_email' => 'stable@example.com', 'status' => 'inactive',
        ])->assertOk();

        $this->acting()->putJson("/api/staff/{$staff->id}", [
            'name' => 'Stable Renamed', 'personal_email' => 'stable@example.com', 'status' => 'inactive',
        ])->assertOk();

        $this->assertSame($this->admin->id, $staff->fresh()->disabled_by);
    }

    public function test_update_still_validates(): void
    {
        $staff = Staff::create(['name' => 'Keep', 'status' => 'active']);

        $this->acting()->putJson("/api/staff/{$staff->id}", [
            'name' => '', 'personal_email' => 'keep@example.com', 'status' => 'active',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame('Keep', $staff->fresh()->name);
    }

    public function test_update_requires_a_status(): void
    {
        $staff = Staff::create(['name' => 'Needs Status', 'status' => 'active']);

        $this->acting()->putJson("/api/staff/{$staff->id}", [
            'name' => 'Needs Status', 'personal_email' => 'needs@example.com',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_updating_staff_requires_control_panel_access(): void
    {
        $staff = Staff::create(['name' => 'Locked', 'status' => 'active']);
        $plain = $this->makeGuestUser($this->tenant, 'Plain', ['email' => 'plain2@test-clinic.test']);

        $this->actingAsTenantUser($plain, $this->tenant)
            ->putJson("/api/staff/{$staff->id}", ['name' => 'Hacked'])
            ->assertStatus(403);
    }

    public function test_staff_list_includes_the_account_count(): void
    {
        $withAccounts = Staff::create(['name' => 'Has Accounts', 'status' => 'active']);
        User::create([
            'staff_id' => $withAccounts->id, 'email' => 'a@test-clinic.test',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Staff::create(['name' => 'No Accounts', 'status' => 'active']);

        $rows = collect($this->acting()->getJson('/api/staff')->assertOk()->json('staff'));

        $this->assertSame(1, $rows->firstWhere('name', 'Has Accounts')['users_count']);
        $this->assertSame(0, $rows->firstWhere('name', 'No Accounts')['users_count']);
    }

    public function test_deleting_staff_requires_control_panel_access(): void
    {
        $staff = Staff::create(['name' => 'Locked', 'status' => 'active']);
        $plain = $this->makeGuestUser($this->tenant, 'Plain', ['email' => 'plain3@test-clinic.test']);

        $this->actingAsTenantUser($plain, $this->tenant)
            ->deleteJson("/api/staff/{$staff->id}")
            ->assertStatus(403);
    }

    public function test_a_staff_member_with_no_linked_account_can_be_deleted(): void
    {
        $staff = Staff::create(['name' => 'No Accounts', 'status' => 'active']);

        $this->acting()->deleteJson("/api/staff/{$staff->id}")->assertOk();

        $this->assertDatabaseMissing('staff', ['id' => $staff->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'staff.deleted',
            'subject_id' => $staff->id,
        ]);
    }

    public function test_deleting_staff_is_blocked_while_a_user_account_is_linked(): void
    {
        $staff = Staff::create(['name' => 'Has Account', 'status' => 'active']);
        User::create([
            'staff_id' => $staff->id, 'email' => 'linked@test-clinic.test',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->acting()->deleteJson("/api/staff/{$staff->id}")->assertStatus(422);

        $this->assertDatabaseHas('staff', ['id' => $staff->id]);
    }
}
