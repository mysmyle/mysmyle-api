<?php

namespace Tests\Feature;

use App\Models\Tenant\Staff;
use App\Models\Tenant\StaffQualification;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class StaffQualificationTest extends TestCase
{
    use BuildsTenantData;

    private $tenant;

    private $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();

        $this->tenant = $this->makeTenant();
        $this->staff = Staff::create(['name' => 'Dr Santos', 'status' => 'active']);
    }

    private function grantSqe($user, string $action = 'view')
    {
        $this->grantModule($user, 'SQE');
        $this->grantCpPermission($user, 'SQE.QUALIFICATIONS', $action);

        return $user;
    }

    public function test_listing_requires_the_sqe_module_and_view_permission(): void
    {
        $user = $this->makeTenantUser($this->tenant, ['email' => 'bare@test-clinic.test']);

        $this->actingAsTenantUser($user, $this->tenant)
            ->getJson('/api/staff-qualifications')
            ->assertStatus(403);
    }

    public function test_staff_options_returns_id_and_name_only(): void
    {
        $user = $this->grantSqe($this->makeTenantUser($this->tenant, ['email' => 'viewer@test-clinic.test']));

        $response = $this->actingAsTenantUser($user, $this->tenant)
            ->getJson('/api/staff-qualifications/staff-options')
            ->assertOk();

        $response->assertJsonPath('staff.0.name', 'Dr Santos');
        $this->assertSame(['id', 'name'], array_keys($response->json('staff.0')));
    }

    public function test_creating_a_qualification_requires_the_add_permission(): void
    {
        $user = $this->grantSqe($this->makeTenantUser($this->tenant, ['email' => 'viewer2@test-clinic.test']), 'view');

        $this->actingAsTenantUser($user, $this->tenant)
            ->postJson('/api/staff-qualifications', [
                'staff_id' => $this->staff->id,
                'title' => 'RN License',
                'status' => 'active',
            ])
            ->assertStatus(403);
    }

    public function test_creates_a_qualification_with_the_required_fields(): void
    {
        $user = $this->grantSqe($this->makeTenantUser($this->tenant, ['email' => 'adder@test-clinic.test']), 'add');

        $this->actingAsTenantUser($user, $this->tenant)
            ->postJson('/api/staff-qualifications', [
                'staff_id' => $this->staff->id,
                'title' => 'RN License',
                'issuing_org' => 'PRC',
                'credential_no' => '12345',
                'issued_on' => '2024-01-01',
                'expires_on' => '2027-01-01',
                'status' => 'active',
            ])
            ->assertStatus(201)
            ->assertJsonPath('qualification.title', 'RN License')
            ->assertJsonPath('qualification.staff.name', 'Dr Santos');

        $this->assertDatabaseHas('staff_qualifications', [
            'staff_id' => $this->staff->id, 'title' => 'RN License', 'credential_no' => '12345',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id, 'action' => 'staff_qualification.created',
        ]);
    }

    public function test_title_and_status_are_required(): void
    {
        $user = $this->grantSqe($this->makeTenantUser($this->tenant, ['email' => 'adder2@test-clinic.test']), 'add');

        $this->actingAsTenantUser($user, $this->tenant)
            ->postJson('/api/staff-qualifications', ['staff_id' => $this->staff->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'status']);
    }

    public function test_expiry_date_must_not_be_before_the_issue_date(): void
    {
        $user = $this->grantSqe($this->makeTenantUser($this->tenant, ['email' => 'adder3@test-clinic.test']), 'add');

        $this->actingAsTenantUser($user, $this->tenant)
            ->postJson('/api/staff-qualifications', [
                'staff_id' => $this->staff->id,
                'title' => 'RN License',
                'status' => 'active',
                'issued_on' => '2024-01-01',
                'expires_on' => '2023-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expires_on');
    }

    public function test_shows_a_single_qualification(): void
    {
        $qualification = StaffQualification::create([
            'staff_id' => $this->staff->id, 'title' => 'RN License', 'status' => 'active',
        ]);
        $user = $this->grantSqe($this->makeTenantUser($this->tenant, ['email' => 'viewer3@test-clinic.test']));

        $this->actingAsTenantUser($user, $this->tenant)
            ->getJson("/api/staff-qualifications/{$qualification->id}")
            ->assertOk()
            ->assertJsonPath('qualification.title', 'RN License')
            ->assertJsonPath('qualification.staff.name', 'Dr Santos');
    }

    /** staff-options must not be swallowed by the {qualificationId} show route. */
    public function test_staff_options_route_is_not_shadowed_by_the_show_route(): void
    {
        $user = $this->grantSqe($this->makeTenantUser($this->tenant, ['email' => 'viewer4@test-clinic.test']));

        $this->actingAsTenantUser($user, $this->tenant)
            ->getJson('/api/staff-qualifications/staff-options')
            ->assertOk()
            ->assertJsonStructure(['staff']);
    }

    public function test_updates_a_qualification(): void
    {
        $qualification = StaffQualification::create([
            'staff_id' => $this->staff->id, 'title' => 'Old Title', 'status' => 'active',
        ]);
        $user = $this->grantSqe($this->makeTenantUser($this->tenant, ['email' => 'editor@test-clinic.test']), 'edit');

        $this->actingAsTenantUser($user, $this->tenant)
            ->putJson("/api/staff-qualifications/{$qualification->id}", [
                'title' => 'New Title', 'status' => 'expired',
            ])
            ->assertOk()
            ->assertJsonPath('qualification.title', 'New Title')
            ->assertJsonPath('qualification.status', 'expired');

        $this->assertSame('expired', $qualification->fresh()->status);
    }

    public function test_deletes_a_qualification(): void
    {
        $qualification = StaffQualification::create([
            'staff_id' => $this->staff->id, 'title' => 'Old Title', 'status' => 'active',
        ]);
        $user = $this->grantSqe($this->makeTenantUser($this->tenant, ['email' => 'deleter@test-clinic.test']), 'edit');

        $this->actingAsTenantUser($user, $this->tenant)
            ->deleteJson("/api/staff-qualifications/{$qualification->id}")
            ->assertOk();

        $this->assertDatabaseMissing('staff_qualifications', ['id' => $qualification->id]);
    }

    public function test_deleting_the_staff_member_also_removes_their_qualifications(): void
    {
        $staff = Staff::create(['name' => 'No Accounts', 'status' => 'active']);
        StaffQualification::create(['staff_id' => $staff->id, 'title' => 'Cert', 'status' => 'active']);
        $user = $this->grantSqe($this->makeTenantUser($this->tenant, ['email' => 'admin2@test-clinic.test']));
        $this->grantModule($user, 'CP');
        $this->grantCpPermission($user, 'CP.STAFF', 'edit');

        $this->actingAsTenantUser($user, $this->tenant)
            ->deleteJson("/api/staff/{$staff->id}")
            ->assertOk();

        $this->assertDatabaseMissing('staff_qualifications', ['staff_id' => $staff->id]);
    }

    public function test_listing_orders_by_soonest_expiry_and_includes_staff_name(): void
    {
        StaffQualification::create([
            'staff_id' => $this->staff->id, 'title' => 'Later', 'status' => 'active', 'expires_on' => '2030-01-01',
        ]);
        StaffQualification::create([
            'staff_id' => $this->staff->id, 'title' => 'Sooner', 'status' => 'active', 'expires_on' => '2025-01-01',
        ]);
        $user = $this->grantSqe($this->makeTenantUser($this->tenant, ['email' => 'lister@test-clinic.test']));

        $response = $this->actingAsTenantUser($user, $this->tenant)->getJson('/api/staff-qualifications')->assertOk();

        $titles = collect($response->json('qualifications'))->pluck('title')->all();
        $this->assertSame(['Sooner', 'Later'], $titles);
        $this->assertSame('Dr Santos', $response->json('qualifications.0.staff.name'));
    }
}
