<?php

namespace Tests\Feature;

use App\Models\Tenant\Staff;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class UserAccountsTest extends TestCase
{
    use BuildsTenantData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
    }

    private function actAsControlPanelUser(): array
    {
        $tenant = $this->makeTenant();
        $user = $this->makeTenantUser($tenant, ['email' => 'admin@test-clinic.test', 'name' => 'Admin One']);
        $this->grantControlPanel($user);

        return [$tenant, $user];
    }

    public function test_display_name_is_the_staff_name_for_staff_linked_accounts(): void
    {
        [$tenant, $admin] = $this->actAsControlPanelUser();

        $response = $this->actingAsTenantUser($admin, $tenant)->getJson('/api/users')->assertOk();

        $row = collect($response->json('users'))->firstWhere('email', 'admin@test-clinic.test');
        $this->assertSame('Admin One', $row['display_name']);
        $this->assertNotNull($row['staff']);
    }

    public function test_display_name_falls_back_to_the_user_name_for_guest_accounts(): void
    {
        [$tenant, $admin] = $this->actAsControlPanelUser();
        $this->makeGuestUser($tenant, 'Walk-in Guest', ['email' => 'guest@test-clinic.test']);

        $response = $this->actingAsTenantUser($admin, $tenant)->getJson('/api/users')->assertOk();

        $guest = collect($response->json('users'))->firstWhere('email', 'guest@test-clinic.test');
        $this->assertNull($guest['staff']);
        $this->assertSame('Walk-in Guest', $guest['display_name']);
    }

    public function test_a_staff_member_with_two_accounts_appears_as_two_rows(): void
    {
        [$tenant, $admin] = $this->actAsControlPanelUser();

        $staff = Staff::create(['name' => 'Dr Reyes', 'status' => 'active']);
        foreach (['reyes.a@test-clinic.test' => 'active', 'reyes.b@test-clinic.test' => 'inactive'] as $email => $status) {
            User::create([
                'staff_id' => $staff->id,
                'email' => $email,
                'password' => Hash::make('password'),
                'status' => $status,
            ]);
        }

        $rows = collect($this->actingAsTenantUser($admin, $tenant)->getJson('/api/users')->json('users'))
            ->where('display_name', 'Dr Reyes')
            ->values();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            ['reyes.a@test-clinic.test', 'reyes.b@test-clinic.test'],
            $rows->pluck('email')->all(),
        );
        $this->assertEqualsCanonicalizing(['active', 'inactive'], $rows->pluck('status')->all());
    }
}
