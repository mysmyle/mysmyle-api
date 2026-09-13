<?php

namespace Database\Seeders;

use App\Models\Landlord\Module;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $clinical = Module::KIND_CLINICAL;
        $system = Module::KIND_SYSTEM;

        $modules = [
            ['name' => 'Registrations & Appointments', 'abbreviation' => 'RAP', 'kind' => $clinical, 'is_visible' => true],
            ['name' => 'Patient Documents Center', 'abbreviation' => 'PDC', 'kind' => $clinical, 'is_visible' => true],
            ['name' => 'Electronic Medical Record', 'abbreviation' => 'EMR', 'kind' => $clinical, 'is_visible' => false],
            ['name' => 'Dental Laboratory', 'abbreviation' => 'LAB', 'kind' => $clinical, 'is_visible' => true],
            ['name' => 'Purchase & Inventory', 'abbreviation' => 'INV', 'kind' => $clinical, 'is_visible' => true],
            ['name' => 'Communication Portal', 'abbreviation' => 'COM', 'kind' => $clinical, 'is_visible' => true],
            ['name' => 'Accounts & Expenditure', 'abbreviation' => 'FIN', 'kind' => $clinical, 'is_visible' => true],
            ['name' => 'Legal & Professionalism', 'abbreviation' => 'LAP', 'kind' => $clinical, 'is_visible' => true],
            ['name' => 'Staff Qualification & Education (HR)', 'abbreviation' => 'SQE', 'kind' => $clinical, 'is_visible' => true],
            ['name' => 'Policies & Procedures', 'abbreviation' => 'P&P', 'kind' => $clinical, 'is_visible' => true],
            ['name' => 'Manual & Guidelines', 'abbreviation' => 'HLP', 'kind' => $clinical, 'is_visible' => true],

            // System modules — app capabilities, not clinical areas. Gated through the
            // same role/user module-access machinery but kept out of the product nav.
            ['name' => 'Control Panel', 'abbreviation' => Module::CONTROL_PANEL, 'kind' => $system, 'is_visible' => true],
        ];

        foreach ($modules as $module) {
            Module::updateOrCreate(
                ['abbreviation' => $module['abbreviation']],
                $module
            );

            Cache::forget("module_id:{$module['abbreviation']}");
        }
    }
}
