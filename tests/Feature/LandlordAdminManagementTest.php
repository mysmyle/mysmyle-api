<?php

namespace Tests\Feature;

use App\Models\Landlord\LandlordAdmin;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class LandlordAdminManagementTest extends TestCase
{
    use BuildsTenantData;

    private $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = $this->makeLandlordAdmin(['email' => 'super@mysmyle.test']);
    }

    public function test_listing_admins_requires_a_landlord_admin(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->getJson('/api/landlord/admins')
            ->assertStatus(401);
    }

    public function test_a_super_admin_can_invite_a_new_admin(): void
    {
        Mail::fake();

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson('/api/landlord/admins', ['name' => 'New Admin', 'email' => 'new@mysmyle.test', 'role' => 'support'])
            ->assertStatus(201)
            ->assertJsonPath('admin.name', 'New Admin')
            ->assertJsonPath('admin.status', 'active')
            ->assertJsonPath('admin.role', 'support');

        $this->assertDatabaseHas('landlord_admins', ['email' => 'new@mysmyle.test', 'password' => null]);
        $this->assertDatabaseHas('landlord_audit_logs', [
            'landlord_admin_id' => $this->superAdmin->id,
            'action' => 'landlord_admin.created',
        ]);
    }

    public function test_the_list_flags_a_pending_setup(): void
    {
        Mail::fake();
        $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson('/api/landlord/admins', ['name' => 'New Admin', 'email' => 'new@mysmyle.test', 'role' => 'support'])
            ->assertStatus(201);

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->getJson('/api/landlord/admins')
            ->assertOk()
            ->assertJsonFragment(['email' => 'new@mysmyle.test', 'setup_pending' => true]);
    }

    public function test_email_must_be_unique(): void
    {
        Mail::fake();

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson('/api/landlord/admins', ['name' => 'Dup', 'email' => 'super@mysmyle.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_an_admin_can_be_disabled_by_another_admin(): void
    {
        Mail::fake();
        $other = $this->makeLandlordAdmin(['email' => 'other@mysmyle.test']);

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->putJson("/api/landlord/admins/{$other->id}", [
                'name' => $other->name, 'email' => $other->email, 'status' => 'disabled', 'role' => $other->role,
            ])
            ->assertOk()
            ->assertJsonPath('admin.status', 'disabled');

        $this->assertDatabaseHas('landlord_audit_logs', [
            'landlord_admin_id' => $this->superAdmin->id,
            'action' => 'landlord_admin.updated',
            'subject_id' => $other->id,
        ]);
    }

    public function test_an_admin_cannot_disable_their_own_account(): void
    {
        $this->actingAsLandlordAdmin($this->superAdmin)
            ->putJson("/api/landlord/admins/{$this->superAdmin->id}", [
                'name' => $this->superAdmin->name, 'email' => $this->superAdmin->email,
                'status' => 'disabled', 'role' => $this->superAdmin->role,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_the_last_active_admin_cannot_be_disabled(): void
    {
        $other = $this->makeLandlordAdmin(['email' => 'other@mysmyle.test']);
        // disable superAdmin first via direct update so only $other is active
        $this->superAdmin->update(['status' => 'disabled']);

        $this->actingAsLandlordAdmin($other)
            ->putJson("/api/landlord/admins/{$other->id}", [
                'name' => $other->name, 'email' => $other->email, 'status' => 'disabled', 'role' => $other->role,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_resending_the_setup_link_is_blocked_once_the_admin_has_a_password(): void
    {
        $other = $this->makeLandlordAdmin(['email' => 'other@mysmyle.test']); // already has a password

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson("/api/landlord/admins/{$other->id}/resend-setup-link")
            ->assertStatus(422);
    }

    public function test_a_super_admin_can_reset_another_admins_password(): void
    {
        Mail::fake();
        $other = $this->makeLandlordAdmin(['email' => 'other@mysmyle.test']);

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson("/api/landlord/admins/{$other->id}/reset-password")
            ->assertOk();

        $this->assertNull(LandlordAdmin::find($other->id)->password);
        $this->assertDatabaseHas('landlord_audit_logs', [
            'landlord_admin_id' => $this->superAdmin->id,
            'action' => 'landlord_admin.password_reset',
            'subject_id' => $other->id,
        ]);
    }
}
