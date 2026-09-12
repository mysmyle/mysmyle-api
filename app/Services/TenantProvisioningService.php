<?php

namespace App\Services;

use App\Jobs\ProvisionTenant;
use App\Mail\SetPasswordLinkMail;
use App\Models\Landlord\Designation;
use App\Models\Landlord\Module;
use App\Models\Landlord\PasswordSetupToken;
use App\Models\Landlord\Permission;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantUser;
use App\Models\Tenant\Role;
use App\Models\Tenant\RoleHasPermission;
use App\Models\Tenant\RoleModuleAccess;
use App\Models\Tenant\Staff;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TenantProvisioningService
{
    public function __construct(
        protected TenantConnectionResolver $resolver,
        protected TenantDatabaseManager $databases,
    ) {}

    /**
     * Controller entry point. The slug and database name are derived from the
     * clinic name. Reserves the tenant record synchronously (so name/email
     * clashes fail fast with a 422) and hands the heavy lifting — database
     * creation, migrations, admin seeding — to a queued job.
     */
    public function startProvisioning(
        string $name,
        string $adminName,
        string $adminEmail,
    ): Tenant {
        $slug = $this->deriveSlug($name);
        $dbName = $this->deriveDbName($slug);

        $this->guardUniqueness($slug, $dbName);
        $this->guardAdminEmail($adminEmail);

        // Re-submitting the create form for a previously failed tenant retries it.
        $tenant = Tenant::where('slug', $slug)->where('status', Tenant::STATUS_FAILED)->first();

        if ($tenant) {
            $tenant->update($this->recordAttributes($name, $slug, $dbName));
        } else {
            $tenant = Tenant::create($this->recordAttributes($name, $slug, $dbName));
        }

        ProvisionTenant::dispatch($tenant->id, $adminName, $adminEmail);

        return $tenant;
    }

    /**
     * Queued job body: create the database, run migrations, seed the admin.
     * Any failure drops the half-built database and marks the tenant failed.
     */
    public function runProvisioning(
        int $tenantId,
        string $adminName,
        string $adminEmail,
    ): void {
        $tenant = Tenant::findOrFail($tenantId);

        try {
            $this->provisionInfrastructure($tenant);
            $this->seedAdmin($tenant, $adminName, $adminEmail);

            $tenant->markActive();
        } catch (Throwable $e) {
            Log::error("Tenant provisioning failed for [{$tenant->slug}]: {$e->getMessage()}");

            $this->compensate($tenant);
            $tenant->markFailed($e->getMessage());

            throw $e;
        }
    }

    /** Called from the job's failed() hook as a last-resort marker. */
    public function markProvisioningFailed(int $tenantId, string $error): void
    {
        $tenant = Tenant::find($tenantId);

        if ($tenant && $tenant->status !== Tenant::STATUS_ACTIVE) {
            $tenant->markFailed($error);
        }
    }

    /**
     * Synchronous, infrastructure-only provisioning for the CLI
     * (`php artisan tenant:create`). No admin user is seeded. Slug and database
     * name default to values derived from the clinic name.
     */
    public function provision(string $name, ?string $slug = null, ?string $dbName = null): Tenant
    {
        $slug ??= $this->deriveSlug($name);
        $dbName ??= $this->deriveDbName($slug);

        $this->guardUniqueness($slug, $dbName);

        $tenant = Tenant::create($this->recordAttributes($name, $slug, $dbName));

        try {
            $this->provisionInfrastructure($tenant);

            $tenant->markActive();
        } catch (Throwable $e) {
            Log::error("Tenant provisioning failed for [{$slug}]: {$e->getMessage()}");

            $this->compensate($tenant);
            $tenant->delete();

            throw $e;
        }

        return $tenant;
    }

    /**
     * Rotate (or create) the dedicated MySQL user for an already-provisioned
     * tenant and confirm the app can connect with it. Used by
     * `php artisan tenant:provision-db-user`.
     */
    public function reprovisionDbUser(Tenant $tenant): void
    {
        $this->databases->createDatabase($tenant->db_name); // no-op if it exists
        $this->databases->createScopedUser($tenant);
        $this->databases->verifyConnection($tenant);
    }

    protected function recordAttributes(string $name, string $slug, string $dbName): array
    {
        return [
            'name' => $name,
            'slug' => $slug,
            'db_name' => $dbName,
            'db_host' => config('database.connections.landlord.host'),
            'db_port' => config('database.connections.landlord.port'),
            // Real credentials are minted (per-tenant MySQL user) when the job
            // runs provisionInfrastructure() — the row never holds root creds.
            'db_username' => '',
            'db_password' => '',
            'status' => Tenant::STATUS_PROVISIONING,
            'provision_error' => null,
        ];
    }

    /**
     * URL/identifier-safe slug from the clinic name. Capped so the derived
     * database name (prefix + slug) stays under MySQL's 64-char limit.
     */
    protected function deriveSlug(string $name): string
    {
        $slug = trim(Str::limit(Str::slug($name), 50, ''), '-');

        if ($slug === '') {
            throw ValidationException::withMessages([
                'tenant_name' => ['The clinic name must contain letters or numbers.'],
            ]);
        }

        return $slug;
    }

    protected function deriveDbName(string $slug): string
    {
        return config('tenancy.db.name_prefix').str_replace('-', '_', $slug);
    }

    protected function guardUniqueness(string $slug, string $dbName): void
    {
        $clash = Tenant::where('status', '!=', Tenant::STATUS_FAILED)
            ->where(fn ($q) => $q->where('slug', $slug)->orWhere('db_name', $dbName))
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'tenant_name' => ['A clinic with this name already exists.'],
            ]);
        }
    }

    protected function guardAdminEmail(string $email): void
    {
        if (TenantUser::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'admin_email' => ['This email is already registered to a clinic.'],
            ]);
        }
    }

    protected function provisionInfrastructure(Tenant $tenant): void
    {
        $this->databases->createDatabase($tenant->db_name);
        $this->databases->createScopedUser($tenant);
        $this->databases->verifyConnection($tenant);

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }

    protected function seedAdmin(Tenant $tenant, string $name, string $email): void
    {
        $this->resolver->bind($tenant);

        // All tenant-database writes commit or roll back together.
        DB::connection('tenant')->transaction(function () use ($tenant, $name, $email) {
            $staff = Staff::create(['name' => $name, 'personal_email' => $email, 'status' => 'active']);

            $user = User::create([
                'staff_id' => $staff->id,
                'email' => $email,
                'status' => 'active',
                // No password — the admin sets it via the emailed link below.
            ]);

            $department = app(DepartmentService::class)->createWithRoles('Administration');

            $adminDesignation = Designation::where('name', 'like', '%Admin%')->first();

            if ($adminDesignation) {
                $role = Role::where('department_id', $department->id)
                    ->where('designation_id', $adminDesignation->id)
                    ->first();

                if ($role) {
                    // Bootstrap: the first admin must reach the Control Panel, or nobody
                    // can grant access to anyone. Set the role template before assignRole()
                    // so the snapshot copy carries it to the user.
                    $this->grantControlPanel($role->id);

                    app(UserRoleService::class)->assignRole($user, $role->id, $tenant->id);
                }
            }
        });

        // Landlord-side login lookup row. Lives on a different connection, so it
        // can't join the transaction above; compensate() removes it on failure.
        TenantUser::create([
            'email' => $email,
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);

        // The admin has no password yet — email them a set-password link.
        $token = app(PasswordSetupService::class)->issue($tenant->id, $email);

        Mail::to($email)->send(new SetPasswordLinkMail(
            $email,
            $name,
            $tenant->name,
            $token,
            PasswordSetupService::TTL_HOURS,
        ));
    }

    protected function grantControlPanel(int $roleId): void
    {
        $moduleId = Module::controlPanelId();

        if (! $moduleId) {
            return;
        }

        RoleModuleAccess::updateOrCreate(
            ['role_id' => $roleId, 'module_id' => $moduleId],
            ['allowed' => true]
        );

        // ...and every CP section permission, or the first admin lands in the
        // Control Panel with no section they can open.
        $cpPermissionIds = Permission::whereHas('station', fn ($q) => $q->where('module_id', $moduleId))
            ->pluck('id');

        foreach ($cpPermissionIds as $permissionId) {
            RoleHasPermission::firstOrCreate([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ], ['created_at' => now()]);
        }
    }

    /**
     * Undo a partial provision. The tenant database + its dedicated user are
     * disposable at this stage; dropping the database discards any tenant-side
     * rows. The landlord-side login row is removed explicitly. The tenant
     * record itself is kept (marked failed) so the create form can retry it.
     */
    protected function compensate(Tenant $tenant): void
    {
        try {
            $this->databases->dropDatabase($tenant->db_name);
            $this->databases->dropScopedUser($tenant);
        } catch (Throwable $e) {
            Log::error("Failed to drop database/user during compensation: {$e->getMessage()}");
        }

        TenantUser::where('tenant_id', $tenant->id)->delete();
        PasswordSetupToken::where('tenant_id', $tenant->id)->delete();
    }
}
