<?php

namespace Tests\Feature;

use App\Jobs\ProvisionTenant;
use App\Mail\SetPasswordLinkMail;
use App\Models\Landlord\Module;
use App\Models\Landlord\Permission;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantUser;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Models\Tenant\UserHasPermission;
use App\Models\Tenant\UserModuleAccess;
use App\Services\TenantProvisioningService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class TenantProvisioningTest extends TestCase
{
    use BuildsTenantData;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seedModules();
        $this->seedDesignations();
    }

    /** Slug + database name are derived from the name; the admin sets their own password via an emailed link. */
    private array $payload = [
        'tenant_name' => 'New Clinic',
        'admin_name' => 'Dr Admin',
        'admin_email' => 'admin@new-clinic.test',
    ];

    public function test_creating_a_tenant_requires_a_landlord_admin(): void
    {
        Queue::fake();

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/landlord/tenants', $this->payload)
            ->assertStatus(401);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_endpoint_reserves_a_record_and_queues_the_job(): void
    {
        Queue::fake();
        $admin = $this->makeLandlordAdmin();

        $this->actingAsLandlordAdmin($admin)
            ->postJson('/api/landlord/tenants', $this->payload)
            ->assertStatus(202)
            ->assertJsonPath('tenant.slug', 'new-clinic')
            ->assertJsonPath('tenant.db_name', 'mysmyle_new_clinic')
            ->assertJsonPath('tenant.status', 'provisioning');

        $this->assertDatabaseHas('tenants', ['slug' => 'new-clinic', 'status' => 'provisioning']);
        // real creds are minted by the job, not stored now
        $this->assertSame('', Tenant::where('slug', 'new-clinic')->first()->db_username);

        Queue::assertPushed(
            ProvisionTenant::class,
            fn (ProvisionTenant $job) => $job->adminEmail === 'admin@new-clinic.test'
                && $job->adminName === 'Dr Admin',
        );
    }

    public static function nameToSlugProvider(): array
    {
        return [
            'spaces' => ['Vision Dental Clinic', 'vision-dental-clinic', 'mysmyle_vision_dental_clinic'],
            'extra whitespace + punctuation' => ['  Bright   Smiles!! ', 'bright-smiles', 'mysmyle_bright_smiles'],
            'digits' => ['Clinic 123', 'clinic-123', 'mysmyle_clinic_123'],
        ];
    }

    #[DataProvider('nameToSlugProvider')]
    public function test_slug_and_database_name_are_derived_from_the_name(string $name, string $slug, string $db): void
    {
        Queue::fake();
        $admin = $this->makeLandlordAdmin();

        $this->actingAsLandlordAdmin($admin)
            ->postJson('/api/landlord/tenants', [
                'tenant_name' => $name,
                'admin_name' => 'A',
                'admin_email' => "admin@{$slug}.test",
            ])
            ->assertStatus(202)
            ->assertJsonPath('tenant.slug', $slug)
            ->assertJsonPath('tenant.db_name', $db);
    }

    public function test_a_name_with_no_usable_characters_is_rejected(): void
    {
        Queue::fake();
        $admin = $this->makeLandlordAdmin();

        $this->actingAsLandlordAdmin($admin)
            ->postJson('/api/landlord/tenants', [...$this->payload, 'tenant_name' => '—  !!!  —'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenant_name');

        Queue::assertNothingPushed();
    }

    public function test_a_very_long_name_stays_within_the_database_identifier_limit(): void
    {
        Queue::fake();
        $admin = $this->makeLandlordAdmin();

        $response = $this->actingAsLandlordAdmin($admin)
            ->postJson('/api/landlord/tenants', [
                ...$this->payload,
                'tenant_name' => str_repeat('Very Long Clinic Name ', 6),
            ])
            ->assertStatus(202);

        $slug = $response->json('tenant.slug');
        $this->assertLessThanOrEqual(50, strlen($slug));
        $this->assertFalse(str_ends_with($slug, '-'));
        $this->assertLessThanOrEqual(64, strlen($response->json('tenant.db_name')));
    }

    public function test_a_duplicate_name_is_rejected(): void
    {
        $this->makeTenant(['slug' => 'new-clinic', 'db_name' => 'mysmyle_new_clinic']);
        $admin = $this->makeLandlordAdmin();

        $this->actingAsLandlordAdmin($admin)
            ->postJson('/api/landlord/tenants', $this->payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenant_name');
    }

    public function test_a_duplicate_admin_email_is_rejected_before_provisioning(): void
    {
        Queue::fake();
        $other = $this->makeTenant(['slug' => 'other', 'db_name' => 'mysmyle_other']);
        TenantUser::create(['email' => 'admin@new-clinic.test', 'tenant_id' => $other->id, 'status' => 'active']);
        $admin = $this->makeLandlordAdmin();

        $this->actingAsLandlordAdmin($admin)
            ->postJson('/api/landlord/tenants', $this->payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_email');

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('tenants', ['slug' => 'new-clinic']);
    }

    public function test_resubmitting_a_failed_tenant_retries_the_same_record(): void
    {
        Queue::fake();
        $failed = $this->makeTenant([
            'slug' => 'new-clinic',
            'db_name' => 'mysmyle_new_clinic',
            'status' => Tenant::STATUS_FAILED,
            'provision_error' => 'earlier boom',
        ]);
        $admin = $this->makeLandlordAdmin();

        $this->actingAsLandlordAdmin($admin)
            ->postJson('/api/landlord/tenants', $this->payload)
            ->assertStatus(202);

        $this->assertDatabaseCount('tenants', 1);
        $failed->refresh();
        $this->assertSame('provisioning', $failed->status);
        $this->assertNull($failed->provision_error);
    }

    public function test_running_the_job_provisions_the_database_and_seeds_the_admin(): void
    {
        $tenant = $this->makeTenant([
            'slug' => 'run-clinic',
            'db_name' => 'mysmyle_run_clinic',
            'status' => Tenant::STATUS_PROVISIONING,
            'db_username' => '',
            'db_password' => '',
        ]);

        app(TenantProvisioningService::class)->runProvisioning(
            $tenant->id, 'Dr Admin', 'admin@run-clinic.test',
        );

        $tenant->refresh();
        $this->assertSame('active', $tenant->status);
        $this->assertNotNull($tenant->provisioned_at);
        $this->assertSame('test_t'.$tenant->id, $tenant->db_username); // minted by the fake manager
        $this->assertContains('mysmyle_run_clinic', $this->tenantDatabases->databasesCreated);

        // landlord-side login row
        $this->assertDatabaseHas('tenant_users', [
            'email' => 'admin@run-clinic.test', 'tenant_id' => $tenant->id,
        ]);

        // tenant-side admin, seeded department + roles, Control Panel access
        $user = User::where('email', 'admin@run-clinic.test')->first();
        $this->assertNotNull($user);
        // no password yet — set via the emailed link, not forced on first login
        $this->assertNull($user->password);
        $this->assertFalse($user->must_change_password);
        $this->assertDatabaseHas('departments', ['name' => 'Administration']);
        $this->assertNotNull($user->role);
        $this->assertSame('Administration', $user->role->department->name);

        $cpModuleId = Module::where('abbreviation', 'CP')->value('id');
        $this->assertTrue(
            UserModuleAccess::where('user_id', $user->id)->where('module_id', $cpModuleId)->value('allowed'),
        );

        // the first admin must land with every CP section permission, not just module access
        $cpPermissionCount = Permission::whereHas(
            'station', fn ($q) => $q->where('module_id', $cpModuleId)
        )->count();
        $this->assertSame(
            $cpPermissionCount,
            UserHasPermission::where('user_id', $user->id)->count(),
        );
        $this->assertGreaterThan(0, $cpPermissionCount);

        // a set-password link was minted + emailed
        $this->assertDatabaseHas('password_setup_tokens', [
            'tenant_id' => $tenant->id, 'email' => 'admin@run-clinic.test', 'used_at' => null,
        ]);
        Mail::assertQueued(
            SetPasswordLinkMail::class,
            fn (SetPasswordLinkMail $mail) => $mail->hasTo('admin@run-clinic.test')
                && $mail->clinicName === $tenant->name,
        );
    }

    public function test_infrastructure_failure_marks_the_tenant_failed_and_compensates(): void
    {
        $this->tenantDatabases->failOn = 'verifyConnection';

        $tenant = $this->makeTenant([
            'slug' => 'boom-clinic',
            'db_name' => 'mysmyle_boom_clinic',
            'status' => Tenant::STATUS_PROVISIONING,
        ]);

        try {
            app(TenantProvisioningService::class)->runProvisioning(
                $tenant->id, 'Dr Admin', 'admin@boom-clinic.test',
            );
            $this->fail('runProvisioning should have re-thrown');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Simulated failure', $e->getMessage());
        }

        $tenant->refresh();
        $this->assertSame('failed', $tenant->status);
        $this->assertStringContainsString('Simulated failure', $tenant->provision_error);

        // compensation cleaned up
        $this->assertContains('mysmyle_boom_clinic', $this->tenantDatabases->databasesDropped);
        $this->assertContains($tenant->id, $this->tenantDatabases->usersDropped);
        $this->assertDatabaseMissing('tenant_users', ['tenant_id' => $tenant->id]);
        $this->assertNull(User::where('email', 'admin@boom-clinic.test')->first());
    }

    public function test_seeding_failure_after_infra_still_compensates(): void
    {
        // a landlord login row already exists for this email, on another tenant
        $other = $this->makeTenant(['slug' => 'other', 'db_name' => 'mysmyle_other']);
        TenantUser::create(['email' => 'clash@test.test', 'tenant_id' => $other->id, 'status' => 'active']);

        $tenant = $this->makeTenant([
            'slug' => 'clash-clinic',
            'db_name' => 'mysmyle_clash_clinic',
            'status' => Tenant::STATUS_PROVISIONING,
        ]);

        try {
            app(TenantProvisioningService::class)->runProvisioning(
                $tenant->id, 'Dr Admin', 'clash@test.test',
            );
            $this->fail('runProvisioning should have re-thrown');
        } catch (\Throwable $e) {
            // duplicate tenant_users.email
        }

        $tenant->refresh();
        $this->assertSame('failed', $tenant->status);
        $this->assertContains('mysmyle_clash_clinic', $this->tenantDatabases->databasesDropped);
        // the clash row for the *other* tenant is untouched
        $this->assertDatabaseHas('tenant_users', ['email' => 'clash@test.test', 'tenant_id' => $other->id]);
    }

    public function test_cli_command_derives_the_slug_and_provisions_infrastructure_only(): void
    {
        $this->artisan('tenant:create', ['name' => 'CLI Clinic'])->assertSuccessful();

        $this->assertDatabaseHas('tenants', [
            'slug' => 'cli-clinic', 'db_name' => 'mysmyle_cli_clinic', 'status' => 'active',
        ]);
        $this->assertContains('mysmyle_cli_clinic', $this->tenantDatabases->databasesCreated);
        // no admin seeded by the CLI path
        $this->assertDatabaseCount('tenant_users', 0);
        $this->assertSame(0, Role::count());
    }

    public function test_cli_command_still_accepts_an_explicit_slug_and_database_name(): void
    {
        $this->artisan('tenant:create', [
            'name' => 'Custom Clinic', 'slug' => 'my-slug', 'db_name' => 'mysmyle_custom',
        ])->assertSuccessful();

        $this->assertDatabaseHas('tenants', ['slug' => 'my-slug', 'db_name' => 'mysmyle_custom']);
    }
}
