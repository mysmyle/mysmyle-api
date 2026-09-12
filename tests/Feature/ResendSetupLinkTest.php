<?php

namespace Tests\Feature;

use App\Mail\SetPasswordLinkMail;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Tenant\User;
use App\Services\PasswordSetupService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class ResendSetupLinkTest extends TestCase
{
    use BuildsTenantData;

    private $tenant;

    private User $admin;

    private LandlordAdmin $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->seedDesignations();

        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeTenantUser($this->tenant, ['email' => 'clinicadmin@test.test']);
        $this->admin->forceFill(['password' => null])->save();

        $this->superAdmin = $this->makeLandlordAdmin();
    }

    private function service(): PasswordSetupService
    {
        return app(PasswordSetupService::class);
    }

    private function resend()
    {
        return $this->actingAsLandlordAdmin($this->superAdmin)
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/resend-setup-link");
    }

    public function test_the_tenant_list_flags_a_pending_admin_setup(): void
    {
        $token = $this->service()->issue($this->tenant->id, $this->admin->email);

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->getJson('/api/landlord/tenants')
            ->assertOk()
            ->assertJsonPath('tenants.0.setup_pending', true);

        // once the link is used, it is no longer pending
        $this->service()->complete($token, 'Str0ng-Pass!word');

        $this->actingAsLandlordAdmin($this->superAdmin)
            ->getJson('/api/landlord/tenants')
            ->assertOk()
            ->assertJsonPath('tenants.0.setup_pending', false);
    }

    public function test_a_super_admin_can_resend_the_link(): void
    {
        Mail::fake();
        $old = $this->service()->issue($this->tenant->id, $this->admin->email);

        $this->resend()->assertOk();

        // the previous token is invalidated, a fresh unused one exists
        $this->assertNull($this->service()->find($old));
        $this->assertDatabaseHas('password_setup_tokens', [
            'tenant_id' => $this->tenant->id, 'email' => $this->admin->email, 'used_at' => null,
        ]);

        Mail::assertQueued(
            SetPasswordLinkMail::class,
            fn (SetPasswordLinkMail $mail) => $mail->hasTo('clinicadmin@test.test')
                && $mail->clinicName === $this->tenant->name,
        );
    }

    public function test_resending_after_the_admin_has_set_up_is_rejected(): void
    {
        Mail::fake();
        $this->admin->forceFill(['password' => Hash::make('already-set-Str0ng!')])->save();

        $this->resend()
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenant');

        Mail::assertNothingQueued();
    }

    public function test_resend_requires_a_landlord_admin(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->postJson("/api/landlord/tenants/{$this->tenant->id}/resend-setup-link")
            ->assertStatus(401);
    }
}
