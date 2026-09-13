<?php

namespace Database\Seeders;

use App\Models\Landlord\Module;
use App\Models\Landlord\Permission;
use App\Models\Landlord\Station;
use Illuminate\Database\Seeder;

class StationSeeder extends Seeder
{
    protected array $standardActions = ['add', 'view', 'edit', 'archive', 'print_export'];

    public function run(): void
    {
        $this->seedRegistrationStations();
        $this->seedStaffQualificationStations();
        $this->seedControlPanelStations();
    }

    protected function seedRegistrationStations(): void
    {
        $rap = Module::where('abbreviation', 'RAP')->firstOrFail();

        $stations = [
            'RAP.PATIENTS' => '00- Patients',
            'RAP.BOOKINGS' => '01- Bookings',
            'RAP.TREATMENT_VER' => '02.1- Treatment Verification',
            'RAP.LAB_CONFIRM' => '02.2- LAB Confirmation',
            'RAP.INS_AUTH' => '02.3- Insurance/Self Authorization',
            'RAP.CONFIRMATIONS' => '02.4- RAP Confirmations',
            'RAP.CHECKIN' => '03- Check-in',
            'RAP.CHECKOUT' => '04- Checkout',
        ];

        foreach ($stations as $code => $name) {
            $station = Station::updateOrCreate(
                ['code' => $code],
                ['module_id' => $rap->id, 'name' => $name]
            );

            $this->seedStandardPermissions($station);
        }

        Permission::updateOrCreate([
            'station_id' => Station::where('code', 'RAP.CONFIRMATIONS')->firstOrFail()->id,
            'action' => 'overruling_access',
        ]);
    }

    protected function seedStaffQualificationStations(): void
    {
        $sqe = Module::where('abbreviation', 'SQE')->firstOrFail();

        $station = Station::updateOrCreate(
            ['code' => 'SQE.QUALIFICATIONS'],
            ['module_id' => $sqe->id, 'name' => 'Staff Qualifications']
        );

        foreach (['view', 'add', 'edit'] as $action) {
            Permission::updateOrCreate([
                'station_id' => $station->id,
                'action' => $action,
            ]);
        }
    }

    protected function seedStandardPermissions(Station $station): void
    {
        foreach ($this->standardActions as $action) {
            Permission::updateOrCreate([
                'station_id' => $station->id,
                'action' => $action,
            ]);
        }
    }

    /**
     * The Control Panel is a system module — its "stations" are the admin
     * sections, each gated by view/add/edit so a role can hold CP access
     * without holding every section. `permission:CP.<CODE>,<action>` on the
     * routes enforces these; see routes/api.php.
     */
    protected function seedControlPanelStations(): void
    {
        $cp = Module::where('abbreviation', Module::CONTROL_PANEL)->firstOrFail();

        $sections = [
            'CP.STAFF' => 'Staff Members',
            'CP.USERS' => 'User Accounts',
            'CP.ROLES' => 'Departments & Roles',
            'CP.CATALOG' => 'Modules & Designations',
            'CP.AUDIT' => 'Audit Log',
        ];

        foreach ($sections as $code => $name) {
            $station = Station::updateOrCreate(
                ['code' => $code],
                ['module_id' => $cp->id, 'name' => $name]
            );

            foreach (['view', 'add', 'edit'] as $action) {
                Permission::updateOrCreate([
                    'station_id' => $station->id,
                    'action' => $action,
                ]);
            }
        }
    }
}
