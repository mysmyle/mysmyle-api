<?php

namespace Database\Seeders;

use App\Models\Landlord\LandlordAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LandlordAdminSeeder extends Seeder
{
    public function run(): void
    {
        LandlordAdmin::updateOrCreate(
            ['email' => 'superadmin@mysmyle.com'],
            [
                'name' => 'MySmyle Super Admin',
                'password' => Hash::make('password'),
                'status' => 'active',
            ]
        );
    }
}
