<?php

namespace Tests\Feature;

use App\Models\Landlord\LandlordPasswordSetupToken;
use App\Services\LandlordPasswordSetupService;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class LandlordSetPasswordTest extends TestCase
{
    use BuildsTenantData;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeLandlordAdmin(['email' => 'newadmin@mysmyle.test']);
        $this->admin->forceFill(['password' => null])->save();
    }

    private function issue(): string
    {
        return app(LandlordPasswordSetupService::class)->issue($this->admin->id);
    }

    public function test_a_valid_token_returns_the_email_and_policy(): void
    {
        $token = $this->issue();

        $this->withHeader('Origin', 'http://localhost')
            ->getJson("/api/landlord/set-password/{$token}")
            ->assertOk()
            ->assertJsonPath('email', 'newadmin@mysmyle.test')
            ->assertJsonPath('password_policy.min_length', config('password_policy.min_length'));
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->getJson('/api/landlord/set-password/not-a-real-token')
            ->assertStatus(404);
    }

    public function test_setting_a_password_from_a_valid_token(): void
    {
        $token = $this->issue();

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/landlord/set-password', [
                'token' => $token,
                'password' => 'Str0ng-Pass!word',
                'password_confirmation' => 'Str0ng-Pass!word',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('Str0ng-Pass!word', $this->admin->fresh()->password));
        $this->assertDatabaseMissing('landlord_password_setup_tokens', [
            'token' => hash('sha256', $token), 'used_at' => null,
        ]);
    }

    public function test_the_new_password_must_meet_the_policy(): void
    {
        $token = $this->issue();

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/landlord/set-password', [
                'token' => $token, 'password' => 'weak', 'password_confirmation' => 'weak',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_a_used_token_cannot_be_reused(): void
    {
        $token = $this->issue();
        $body = fn () => [
            'token' => $token, 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word',
        ];

        $this->withHeader('Origin', 'http://localhost')->postJson('/api/landlord/set-password', $body())->assertOk();

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/landlord/set-password', $body())
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $token = $this->issue();
        LandlordPasswordSetupToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->withHeader('Origin', 'http://localhost')
            ->getJson("/api/landlord/set-password/{$token}")
            ->assertStatus(404);
    }

    public function test_issuing_a_new_token_invalidates_the_previous_one(): void
    {
        $first = $this->issue();
        $second = $this->issue();

        $service = app(LandlordPasswordSetupService::class);
        $this->assertNull($service->find($first));
        $this->assertNotNull($service->find($second));
    }

    public function test_the_account_cannot_log_in_until_the_password_is_set(): void
    {
        $token = $this->issue();

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/landlord/login', ['email' => 'newadmin@mysmyle.test', 'password' => 'anything'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/landlord/set-password', [
                'token' => $token, 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word',
            ])->assertOk();

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/landlord/login', ['email' => 'newadmin@mysmyle.test', 'password' => 'Str0ng-Pass!word'])
            ->assertOk();
    }
}
