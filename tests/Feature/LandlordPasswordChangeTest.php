<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class LandlordPasswordChangeTest extends TestCase
{
    use BuildsTenantData;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeLandlordAdmin(['password' => Hash::make('OldPass1!2345')]);
    }

    public function test_changing_password_requires_a_landlord_admin(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/landlord/password', [
                'current_password' => 'OldPass1!2345',
                'password' => 'Str0ng-New!word',
                'password_confirmation' => 'Str0ng-New!word',
            ])
            ->assertStatus(401);
    }

    public function test_an_admin_can_change_their_own_password(): void
    {
        $this->actingAsLandlordAdmin($this->admin)
            ->postJson('/api/landlord/password', [
                'current_password' => 'OldPass1!2345',
                'password' => 'Str0ng-New!word',
                'password_confirmation' => 'Str0ng-New!word',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('Str0ng-New!word', $this->admin->fresh()->password));
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $this->actingAsLandlordAdmin($this->admin)
            ->postJson('/api/landlord/password', [
                'current_password' => 'wrong-password',
                'password' => 'Str0ng-New!word',
                'password_confirmation' => 'Str0ng-New!word',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('OldPass1!2345', $this->admin->fresh()->password));
    }

    public function test_the_new_password_must_meet_the_policy(): void
    {
        $this->actingAsLandlordAdmin($this->admin)
            ->postJson('/api/landlord/password', [
                'current_password' => 'OldPass1!2345',
                'password' => 'weak',
                'password_confirmation' => 'weak',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        $this->actingAsLandlordAdmin($this->admin)
            ->postJson('/api/landlord/password', [
                'current_password' => 'OldPass1!2345',
                'password' => 'Str0ng-New!word',
                'password_confirmation' => 'Different!word9',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }
}
