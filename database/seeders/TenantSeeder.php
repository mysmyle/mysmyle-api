<?php

namespace Database\Seeders;

use App\Models\Landlord\Designation;
use App\Models\Landlord\Module;
use App\Models\Landlord\Permission;
use App\Models\Landlord\TenantUser;
use App\Models\Tenant\Role;
use App\Models\Tenant\RoleHasPermission;
use App\Models\Tenant\RoleModuleAccess;
use App\Models\Tenant\Staff;
use App\Models\Tenant\User;
use App\Services\DepartmentService;
use App\Services\TenantProvisioningService;
use App\Services\UserRoleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = app(TenantProvisioningService::class)->provision(
            name: 'Vision Dental Clinic',
            slug: 'vdc',
            dbName: 'mysmyle_vdc',
        );

        TenantUser::create([
            'email' => 'admin@visiondentalclinic.com',
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);

        $staff = Staff::create([
            'name' => 'VDC Admin',
            'personal_email' => 'admin@visiondentalclinic.com',
            'gender' => 'female',
            'date_of_birth' => '1990-01-01',
            'status' => 'active',
        ]);

        $user = User::create([
            'staff_id' => $staff->id,
            'email' => 'admin@visiondentalclinic.com',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $departmentService = app(DepartmentService::class);

        $departments = [
            '01- RAP (Registration and Appointment)',
            '02, 03- INS/SLF (Insurance/Selfpay)',
            '04- TTT (Treatment)',
            '05- LAB (Laboratory and Preparation)',
            '06- PEC (Patient Education and Communication)',
            '07- PCI (Prevention & Control Of Infection)',
            '08- FMS (Facility Management and Safety)',
            '09- FIN (Finance/Inventory)',
            '10- LAP (Legal & Professionalism)',
            '11- SQE (Staff Qualification and Education)',
            '12- MOI (Management of Information)',
            '13- QMS (Quality Management Systems)',
        ];

        $firstDepartment = null;

        foreach ($departments as $name) {
            $department = $departmentService->createWithRoles($name);
            $firstDepartment ??= $department;
        }

        // Assign the seeded admin to the RAP + Admin (highest level) role, as a sensible default
        $adminRole = Role::where('department_id', $firstDepartment->id)
            ->whereHas('department') // sanity check relation exists
            ->get()
            ->first();

        // Simpler: fetch the Admin-level designation explicitly
        $adminDesignation = Designation::where('name', 'like', '%Admin%')->first();

        if ($adminDesignation) {
            $role = Role::where('department_id', $firstDepartment->id)
                ->where('designation_id', $adminDesignation->id)
                ->first();

            if ($role) {
                $this->grantControlPanel($role->id);
                app(UserRoleService::class)->assignRole($user, $role->id, $tenant->id);
            }
        }

        // Other 2 Users
        $additionalStaff = [
            ['name' => 'Second Admin User', 'gender' => 'male', 'date_of_birth' => '1988-03-20', 'email' => 'admin2@visiondentalclinic.com'],
            ['name' => 'Third Admin User', 'gender' => 'female', 'date_of_birth' => '1992-07-11', 'email' => 'admin3@visiondentalclinic.com'],
        ];

        foreach ($additionalStaff as $person) {
            TenantUser::create([
                'email' => $person['email'],
                'tenant_id' => $tenant->id,
                'status' => 'active',
            ]);

            $staffRecord = Staff::create([
                'name' => $person['name'],
                'personal_email' => $person['email'],
                'gender' => $person['gender'],
                'date_of_birth' => $person['date_of_birth'],
                'status' => 'active',
            ]);

            $additionalUser = User::create([
                'staff_id' => $staffRecord->id,
                'email' => $person['email'],
                'password' => Hash::make('password'),
                'status' => 'active',
            ]);

            app(UserRoleService::class)->assignRole($additionalUser, $role->id, $tenant->id);
        }
    }

    /** Give a role Control Panel module access + every CP section permission. */
    private function grantControlPanel(int $roleId): void
    {
        $moduleId = Module::controlPanelId();

        RoleModuleAccess::updateOrCreate(
            ['role_id' => $roleId, 'module_id' => $moduleId],
            ['allowed' => true],
        );

        $cpPermissionIds = Permission::whereHas('station', fn ($q) => $q->where('module_id', $moduleId))->pluck('id');

        foreach ($cpPermissionIds as $permissionId) {
            RoleHasPermission::firstOrCreate(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['created_at' => now()],
            );
        }
    }
}
