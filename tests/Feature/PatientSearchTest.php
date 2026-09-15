<?php

namespace Tests\Feature;

use App\Models\Landlord\Module;
use App\Models\Landlord\Permission;
use App\Models\Landlord\Station;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientContactNumber;
use App\Models\Tenant\PatientLookup;
use App\Models\Tenant\PatientPayer;
use App\Models\Tenant\Payer;
use App\Models\Tenant\User;
use App\Models\Tenant\UserHasPermission;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsTenantData;
use Tests\TestCase;

class PatientSearchTest extends TestCase
{
    use BuildsTenantData;

    private $tenant;

    private $clerk;

    private array $lookups = [];

    private $payer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModules();
        $this->seedRapPatientsStation();

        $this->tenant = $this->makeTenant();
        $this->clerk = $this->makeTenantUser($this->tenant, ['email' => 'clerk@test-clinic.test', 'name' => 'Clerk']);
        $this->grantModule($this->clerk, 'RAP');
        $this->grantRapPatientsView($this->clerk);

        $this->seedPatientReferenceData();
    }

    /** Mirrors StationSeeder's RAP station list, which BuildsTenantData only seeds for CP. */
    private function seedRapPatientsStation(): void
    {
        $rap = Module::where('abbreviation', 'RAP')->firstOrFail();
        $station = Station::updateOrCreate(
            ['code' => 'RAP.PATIENTS'],
            ['module_id' => $rap->id, 'name' => '00- Patients'],
        );

        foreach (['view', 'add', 'edit'] as $action) {
            Permission::updateOrCreate(['station_id' => $station->id, 'action' => $action]);
        }
    }

    private function grantRapPatientsView(User $user): void
    {
        $permissionId = Permission::where('action', 'view')
            ->whereHas('station', fn ($q) => $q->where('code', 'RAP.PATIENTS'))
            ->value('id');

        UserHasPermission::firstOrCreate(
            ['user_id' => $user->id, 'permission_id' => $permissionId],
            ['created_at' => now()],
        );

        Cache::flush();
    }

    private function seedPatientReferenceData(): void
    {
        $rows = [
            ['gender', 'M', 'Male'],
            ['contact_type', 'primary', 'Primary mobile'],
            ['contact_type', 'whatsapp', 'WhatsApp'],
        ];

        foreach ($rows as [$type, $value, $name]) {
            $this->lookups[$type.'.'.$value] = PatientLookup::create([
                'type' => $type, 'value' => $value, 'name' => $name,
            ]);
        }

        $this->payer = Payer::create([
            'name' => '01- Thiqa Ins.Co.', 'display_name' => '01- Thiqa', 'is_insurance' => true,
        ]);
    }

    private function makePatient(int $chart, string $fullName, ?string $mobile = null): Patient
    {
        $patient = Patient::create([
            'chart' => $chart,
            'full_name' => $fullName,
            'gender_id' => $this->lookups['gender.M']->id,
            'date_of_birth' => '1990-06-15',
        ]);

        if ($mobile) {
            PatientContactNumber::create([
                'patient_id' => $patient->id,
                'contact_type_id' => $this->lookups['contact_type.primary']->id,
                'contact_number' => $mobile,
            ]);
        }

        return $patient;
    }

    private function search(array $params)
    {
        return $this->actingAsTenantUser($this->clerk, $this->tenant)
            ->getJson('/api/patients/search?'.http_build_query($params));
    }

    public function test_searching_requires_the_rap_module(): void
    {
        $outsider = $this->makeGuestUser($this->tenant, 'Outsider', ['email' => 'outsider@test-clinic.test']);

        $this->actingAsTenantUser($outsider, $this->tenant)
            ->getJson('/api/patients/search?q=ali')
            ->assertStatus(403);
    }

    public function test_module_access_alone_is_not_enough_without_the_patients_permission(): void
    {
        $viewer = $this->makeGuestUser($this->tenant, 'Viewer', ['email' => 'viewer@test-clinic.test']);
        $this->grantModule($viewer, 'RAP');

        $this->actingAsTenantUser($viewer, $this->tenant)
            ->getJson('/api/patients/search?q=ali')
            ->assertStatus(403);
    }

    public function test_a_query_of_at_least_two_characters_is_required(): void
    {
        $this->search([])->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search(['q' => 'a'])->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_finds_a_patient_by_every_word_of_their_name_in_any_order(): void
    {
        $this->makePatient(34541, 'AADEL ALI SAEED ALKAABI');
        $this->makePatient(34542, 'Ali Hassan Mansour');

        $this->search(['q' => 'alkaabi aadel'])
            ->assertOk()
            ->assertJsonCount(1, 'patients')
            ->assertJsonPath('patients.0.chart', 34541)
            ->assertJsonPath('patients.0.full_name', 'AADEL ALI SAEED ALKAABI');
    }

    public function test_finds_a_patient_by_chart_number(): void
    {
        $this->makePatient(34541, 'AADEL ALI SAEED ALKAABI');
        $this->makePatient(99999, 'Someone Else');

        $this->search(['q' => '34541'])
            ->assertOk()
            ->assertJsonCount(1, 'patients')
            ->assertJsonPath('patients.0.chart', 34541);
    }

    /** The chart itself must outrank a longer chart that merely starts with it. */
    public function test_an_exact_chart_match_is_ordered_first(): void
    {
        $this->makePatient(3454, 'Zaid Exact Match');
        $this->makePatient(34541, 'Aaron Prefix Match');

        $this->search(['q' => '3454'])
            ->assertOk()
            ->assertJsonCount(2, 'patients')
            ->assertJsonPath('patients.0.chart', 3454);
    }

    /** A number the caller reads out may carry a country code or a leading zero. */
    public function test_finds_a_patient_by_the_tail_of_their_phone_number(): void
    {
        $this->makePatient(34541, 'AADEL ALI SAEED ALKAABI', '505605345');
        $this->makePatient(34542, 'Ali Hassan Mansour', '501112222');

        $this->search(['q' => '5605345'])
            ->assertOk()
            ->assertJsonCount(1, 'patients')
            ->assertJsonPath('patients.0.chart', 34541)
            ->assertJsonPath('patients.0.mobile.number', '505605345');
    }

    public function test_wildcards_typed_into_the_box_are_literals_not_wildcards(): void
    {
        $this->makePatient(34541, 'AADEL ALI SAEED ALKAABI');

        $this->search(['q' => '%%'])->assertOk()->assertJsonCount(0, 'patients');
    }

    public function test_returns_the_primary_active_cover_with_the_patient(): void
    {
        $patient = $this->makePatient(34541, 'AADEL ALI SAEED ALKAABI');
        PatientPayer::create([
            'patient_id' => $patient->id, 'payer_id' => $this->payer->id,
            'insurance_number' => '5374581', 'expiry_date' => '2024-04-28',
            'is_primary' => true, 'active' => true,
        ]);

        $this->search(['q' => 'aadel'])
            ->assertOk()
            ->assertJsonPath('patients.0.payer.name', '01- Thiqa')
            ->assertJsonPath('patients.0.payer.is_insurance', true)
            ->assertJsonPath('patients.0.payer.insurance_number', '5374581')
            ->assertJsonPath('patients.0.payer.expired', true);
    }

    /** Unverified cover is not lapsed cover — see PatientPayer::isExpired. */
    public function test_cover_with_an_unknown_expiry_is_not_reported_as_expired(): void
    {
        $patient = $this->makePatient(34541, 'AADEL ALI SAEED ALKAABI');
        PatientPayer::create([
            'patient_id' => $patient->id, 'payer_id' => $this->payer->id,
            'expiry_date' => null, 'expiry_unknown' => true, 'is_primary' => true, 'active' => true,
        ]);

        $this->search(['q' => 'aadel'])->assertOk()->assertJsonPath('patients.0.payer.expired', false);
    }

    public function test_results_are_capped_at_the_limit_and_say_so(): void
    {
        foreach (range(1, 6) as $i) {
            $this->makePatient(40000 + $i, 'Fatima Test Patient '.$i);
        }

        $this->search(['q' => 'fatima', 'limit' => 3])
            ->assertOk()
            ->assertJsonCount(3, 'patients')
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('limit', 3);

        $this->search(['q' => 'fatima', 'limit' => 25])
            ->assertOk()
            ->assertJsonCount(6, 'patients')
            ->assertJsonPath('has_more', false);
    }

    public function test_a_soft_deleted_patient_is_not_returned(): void
    {
        $patient = $this->makePatient(34541, 'AADEL ALI SAEED ALKAABI');
        $patient->delete();

        $this->search(['q' => 'aadel'])->assertOk()->assertJsonCount(0, 'patients');
    }
}
