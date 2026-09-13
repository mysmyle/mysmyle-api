<?php

namespace Tests\Concerns;

use App\Models\Landlord\Designation;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\Module;
use App\Models\Landlord\Permission;
use App\Models\Landlord\Station;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantUser;
use App\Models\Tenant\Staff;
use App\Models\Tenant\User;
use App\Models\Tenant\UserHasPermission;
use App\Models\Tenant\UserModuleAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

trait BuildsTenantData
{
    /** CP admin sections, mirrors StationSeeder::seedControlPanelStations. */
    protected array $cpStations = ['CP.STAFF', 'CP.USERS', 'CP.ROLES', 'CP.CATALOG', 'CP.AUDIT'];

    protected function seedModules(): void
    {
        $cp = Module::updateOrCreate(['abbreviation' => 'CP'], [
            'name' => 'Control Panel', 'kind' => Module::KIND_SYSTEM, 'is_visible' => true,
        ]);
        Module::updateOrCreate(['abbreviation' => 'RAP'], [
            'name' => 'Registrations & Appointments', 'kind' => Module::KIND_CLINICAL, 'is_visible' => true,
        ]);
        $sqe = Module::updateOrCreate(['abbreviation' => 'SQE'], [
            'name' => 'Staff Qualification & Education', 'kind' => Module::KIND_CLINICAL, 'is_visible' => true,
        ]);

        foreach ($this->cpStations as $code) {
            $station = Station::updateOrCreate(['code' => $code], ['module_id' => $cp->id, 'name' => $code]);
            foreach (['view', 'add', 'edit'] as $action) {
                Permission::updateOrCreate(['station_id' => $station->id, 'action' => $action]);
            }
        }

        $qualifications = Station::updateOrCreate(
            ['code' => 'SQE.QUALIFICATIONS'],
            ['module_id' => $sqe->id, 'name' => 'Staff Qualifications'],
        );
        foreach (['view', 'add', 'edit'] as $action) {
            Permission::updateOrCreate(['station_id' => $qualifications->id, 'action' => $action]);
        }
    }

    /** landlord permission id for e.g. ('CP.STAFF', 'view'). */
    protected function cpPermissionId(string $stationCode, string $action): int
    {
        return Permission::where('action', $action)
            ->whereHas('station', fn ($q) => $q->where('code', $stationCode))
            ->value('id');
    }

    /** Grant one CP section permission to a user. Flushes the permission cache so a later request re-reads it. */
    protected function grantCpPermission(User $user, string $stationCode, string $action): void
    {
        UserHasPermission::firstOrCreate([
            'user_id' => $user->id,
            'permission_id' => $this->cpPermissionId($stationCode, $action),
        ], ['created_at' => now()]);

        Cache::flush();
    }

    /** CP module access + every CP section permission — a full control-panel admin. */
    protected function grantControlPanel(User $user): void
    {
        $this->grantModule($user, 'CP');

        foreach ($this->cpStations as $code) {
            foreach (['view', 'add', 'edit'] as $action) {
                $this->grantCpPermission($user, $code, $action);
            }
        }
    }

    protected function seedDesignations(): void
    {
        foreach ([['01- Guest', 1], ['02- Coordinator', 2], ['03- Officer', 3], ['04- Admin', 4]] as [$name, $level]) {
            Designation::updateOrCreate(['name' => $name], ['level' => $level]);
        }
        Designation::flushCatalog();
    }

    protected function makeTenant(array $attributes = []): Tenant
    {
        return Tenant::create(array_merge([
            'name' => 'Test Clinic',
            'slug' => 'test-clinic',
            'db_name' => 'mysmyle_test',
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_username' => 'test',
            'db_password' => 'test',
            'status' => Tenant::STATUS_ACTIVE,
        ], $attributes));
    }

    protected function makeTenantUser(Tenant $tenant, array $attributes = []): User
    {
        $email = $attributes['email'] ?? 'user@test-clinic.test';

        $staff = Staff::create([
            'name' => $attributes['name'] ?? 'Test User',
            'personal_email' => $attributes['personal_email'] ?? $email,
            'status' => 'active',
        ]);

        TenantUser::create(['email' => $email, 'tenant_id' => $tenant->id, 'status' => 'active']);

        return User::create(array_merge([
            'staff_id' => $staff->id,
            'email' => $email,
            'password' => Hash::make('password'),
            'status' => 'active',
        ], array_diff_key($attributes, ['name' => true])));
    }

    /** A user with no linked staff record — its own `name` is the display name. */
    protected function makeGuestUser(Tenant $tenant, string $name, array $attributes = []): User
    {
        $email = $attributes['email'] ?? 'guest@test-clinic.test';

        TenantUser::create(['email' => $email, 'tenant_id' => $tenant->id, 'status' => 'active']);

        return User::create(array_merge([
            'staff_id' => null,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'status' => 'active',
        ], $attributes));
    }

    protected function grantModule(User $user, string $abbreviation): void
    {
        $moduleId = Module::where('abbreviation', $abbreviation)->value('id');

        UserModuleAccess::updateOrCreate(
            ['user_id' => $user->id, 'module_id' => $moduleId],
            ['allowed' => true],
        );
    }

    protected function makeLandlordAdmin(array $attributes = []): LandlordAdmin
    {
        return LandlordAdmin::create(array_merge([
            'name' => 'Super Admin',
            'email' => 'super@mysmyle.test',
            'password' => Hash::make('password'),
            'status' => 'active',
            'role' => LandlordAdmin::ROLE_SUPER_ADMIN,
        ], $attributes));
    }
}
