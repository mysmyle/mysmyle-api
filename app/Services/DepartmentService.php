<?php

namespace App\Services;

use App\Models\Landlord\Designation;
use App\Models\Tenant\Department;
use App\Models\Tenant\Role;
use Illuminate\Support\Facades\DB;

class DepartmentService
{
    public function createWithRoles(string $name): Department
    {
        return DB::connection('tenant')->transaction(function () use ($name) {
            $department = Department::create(['name' => $name]);

            $designations = Designation::all();

            foreach ($designations as $designation) {
                Role::create([
                    'department_id' => $department->id,
                    'designation_id' => $designation->id,
                ]);
            }

            return $department;
        });
    }
}
