<?php

namespace Database\Seeders;

use App\Models\Landlord\Designation;
use Illuminate\Database\Seeder;

class DesignationSeeder extends Seeder
{
    public function run(): void
    {
        $designations = [
            ['name' => '01- Guest',       'level' => 1],
            ['name' => '02- Coordinator', 'level' => 2],
            ['name' => '03- Officer',     'level' => 3],
            ['name' => '04- Admin',       'level' => 4],
        ];

        foreach ($designations as $designation) {
            Designation::updateOrCreate(
                ['name' => $designation['name']],
                $designation
            );
        }

        Designation::flushCatalog();
    }
}
