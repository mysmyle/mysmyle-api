<?php

namespace App\Console\Legacy;

use Illuminate\Support\Carbon;

/**
 * legacy.patient_registration -> patients
 *                             + patient_contact_numbers
 *                             + patient_emergency_contacts
 *                             + patient_payers
 *
 * One legacy table holding four entities. patients.id is the legacy id, so the
 * import needs no crosswalk; child rows are re-found by their natural key.
 *
 * The rules below are not guesses — each was measured against the live legacy
 * database before it was written. Where a number appears in a comment, it came
 * from a query.
 */
class LegacyPatientImporter extends BaseLegacyImporter
{
    /**
     * Legacy `Date_of_birth` is a varchar holding four different shapes. Slash
     * dates are DAY/MONTH/YEAR, which is the single most important rule in this
     * file: Carbon::parse() reads them as US month/day, and the previous system
     * did exactly that — silently swapping 364 birth dates and losing 562 more
     * to NULL where the day exceeded 12.
     *
     * Proof, re-measured on live: 562 rows have a first part > 12 and ZERO rows
     * have a second part > 12. There is no reading of this data under which the
     * first part is a month.
     */
    private const DOB_SENTINEL = '01/01/1900';   // 1,479 rows meaning "unknown"

    /**
     * Legacy uses more than one "unknown date" sentinel, and the second one
     * parses as a VALID date so it slips past any format check: `0001-01-01`,
     * on 27 patient rows. The same value appears in Insurance_Card_Expiry.
     * Anything before this floor is a sentinel or a typo, never a birth date —
     * the oldest verified human on record was born in 1875.
     */
    private const DOB_FLOOR = '1900-01-01';

    /** Reserved test charts. Legacy carried these in a PHP array literal. */
    private const TEST_CHARTS = [
        9000009, 8000008, 7000007, 6000006, 5000005, 4000004, 3000003,
        2000002, 2075602, 1000005, 1000004, 1000003, 1000002, 1000001,
        777777, 111111,
    ];

    /**
     * Legacy value -> patient_lookups.value, for the columns where the two
     * differ. Everything not listed maps to itself, because patient_lookups.value
     * deliberately holds the legacy string.
     */
    private const VALUE_MAP = [
        // 306 rows hold the literal '0' from an unvalidated <select>.
        'gender' => ['0' => 'unknown'],
        // 'ENGL' is a typo for ENG.
        'language' => ['engl' => 'eng'],
        'patient_status' => ['y' => 'active', 'n' => 'inactive'],
    ];

    /**
     * Demonyms that appear in patient data but in NO legacy country column, and
     * the two spellings that two countries claim with equal precedence.
     * Applied last, so they win over the derived map.
     */
    private const MANUAL_NATIONALITIES = [
        'american' => 237,      // United States, not US Minor Outlying Islands
        'dominican' => 64,      // Dominican Republic, not Dominica
        'swede' => 215,
        'dutchman' => 157,
        'luxembourger' => 130,
        'pole' => 177,
        'spaniard' => 209,
        'monacan' => 147,
    ];

    /** Legacy phone column -> contact_type lookup value. */
    private const PHONE_COLUMNS = [
        'Patient_mobile_phone1' => 'primary',
        'pt_whatsapp' => 'whatsapp',
        'Patient_mobile_phone2' => 'alternative',
    ];

    private array $lookups = [];        // type => [value => id]
    private array $nationalities = [];  // normalised alias => country id
    private array $phoneCodes = [];     // phone_code => country id
    private array $payerIds = [];
    private array $policyIds = [];
    private array $packageIds = [];        // canonical name => package id
    private array $packageOwners = [];     // package id => payer id
    private array $thirdPartyOwners = [];  // third party id => payer id

    public function label(): string
    {
        return 'patients';
    }

    public function run(): void
    {
        $this->loadReferenceData();

        $seenCharts = [];
        $testCharts = array_fill_keys(self::TEST_CHARTS, true);

        foreach ($this->legacy()->table('patient_registration')->orderBy('id')->lazyById(500, 'id')->chunk(500) as $rows) {
            $patients = [];
            $ids = [];

            foreach ($rows as $row) {
                $this->read++;

                $chart = (int) ($row->Chart ?? 0);

                if ($chart === 0) {
                    $this->skip($row->id, 'no chart number');

                    continue;
                }

                // Duplicate resolution. Rows arrive in id order, so the first
                // sighting of a chart is the lowest legacy id and wins. 18
                // charts span 52 rows: 16 are exact double-submits (identical
                // name, phone, date of birth and payer) and the other two are
                // the test charts 777777 and 1000001. Nothing real is lost.
                if (isset($seenCharts[$chart])) {
                    $this->skip($row->id, "duplicate chart {$chart} — kept legacy id {$seenCharts[$chart]}");

                    continue;
                }

                $seenCharts[$chart] = (int) $row->id;

                if (isset($testCharts[$chart])) {
                    $this->skip($row->id, "reserved test chart {$chart} — imported, flag before going live");
                }

                $patients[] = $this->mapPatient($row);
                $ids[(int) $row->id] = $row;
            }

            $this->upsertBatch('patients', $patients, ['id'], [
                'nationality_id', 'title_id', 'gender_id', 'marital_status_id', 'religion_id',
                'language_id', 'preferred_comm_language_id', 'membership_type_id', 'status_id',
                'chart', 'full_name', 'first_name', 'middle_name', 'last_name', 'nickname',
                'date_of_birth', 'email', 'updated_at',
            ]);

            $this->written += \count($patients);

            $this->importContactNumbers($ids);
            $this->importEmergencyContacts($ids);
            $this->importPayers($ids);
        }

        $this->info('patients written: '.$this->written);
    }

    // ── Reference data ────────────────────────────────────────────────────────

    private function loadReferenceData(): void
    {
        // Keyed by LOWERCASED value. Legacy stores the same value in more than
        // one casing — `Gender` holds 'Male' 5,906 times and 'male' 175 times,
        // 'Female' 4,051 and 'female' 135 — and MySQL's utf8mb4_general_ci
        // collation hides that from any GROUP BY, so it does not show up in a
        // distribution check. A case-sensitive PHP lookup silently dropped 310
        // patients' gender to NULL. Folding both sides is the fix; no two
        // values within a type collide when lowercased.
        foreach ($this->tenant()->table('patient_lookups')->get(['id', 'type', 'value']) as $row) {
            $this->lookups[$row->type][mb_strtolower($row->value)] = (int) $row->id;
        }

        // '0' is excluded deliberately: Antarctica and Bouvet Island carry it as
        // a placeholder, and as a prefix it matches any number beginning with a
        // zero. It stripped a leading digit off "00000000" and filed the patient
        // under Antarctica.
        $this->phoneCodes = $this->tenant()->table('countries')
            ->whereNotNull('phone_code')
            ->whereNotIn('phone_code', ['', '0'])
            ->pluck('id', 'phone_code')
            ->map(fn ($id) => (int) $id)->toArray();

        $this->payerIds = $this->tenant()->table('payers')->pluck('id')->flip()->toArray();
        $this->policyIds = $this->tenant()->table('payer_policies')->pluck('payer_id', 'id')->toArray();

        // Ownership, not just existence — a package or third party is only valid
        // for the payer it belongs to. See resolvePackageOrThirdParty().
        $this->thirdPartyOwners = $this->tenant()->table('payer_third_parties')
            ->pluck('payer_id', 'id')->map(fn ($id) => (int) $id)->toArray();
        $this->packageOwners = $this->tenant()->table('payer_packages')
            ->pluck('payer_id', 'id')->map(fn ($id) => (int) $id)->toArray();

        $this->packageIds = $this->tenant()->table('payer_packages')
            ->pluck('id', 'name')->map(fn ($id) => (int) $id)->toArray();

        $this->buildNationalityMap();
    }

    /**
     * Build the nationality lookup IN MEMORY from the legacy countries table.
     *
     * This is deliberately not a database table. Legacy
     * patient_registration.Nationality holds a string copied verbatim from
     * legacy countries.nationality — comma lists included ("Emirati, Emirian,
     * Emiri" on 2,067 patients, "Philippine, Filipino" on 679, "British, UK" on
     * 175). Matching only a clean demonym, as the previous importer did, drops
     * 3,132 patients' nationality.
     *
     * Because CountrySeeder preserves legacy country_id as countries.id, the id
     * this resolves to IS the id to write. Verified: all 249 legacy rows match a
     * seeded row by id, same country in every case.
     *
     * Precedence, lowest wins — the last two matter:
     *   1 the whole nationality string       "Emirati, Emirian, Emiri"
     *   2 one element of it                  "Filipino"
     *   3 the country name                   "Egypt"
     *   4 the Shafafiya code — LAST, because legacy has Niger and Nigeria's
     *     codes SWAPPED and files "Indian" under British Indian Ocean Territory.
     *     Ranking it above `nationality` would send 527 Indian patients to an
     *     uninhabited atoll.
     */
    private function buildNationalityMap(): void
    {
        $byPrecedence = [];

        foreach ($this->legacy()->table('countries')->get() as $c) {
            $id = (int) $c->country_id;
            $nationality = trim((string) ($c->nationality ?? ''));

            if ($nationality !== '') {
                $byPrecedence[1][self::normalise($nationality)] ??= $id;

                foreach (explode(',', $nationality) as $part) {
                    if (($part = trim($part)) !== '') {
                        $byPrecedence[2][self::normalise($part)] ??= $id;
                    }
                }
            }

            if (($name = trim((string) ($c->en_short_name ?? ''))) !== '') {
                $byPrecedence[3][self::normalise($name)] ??= $id;
            }

            if (($shafafiya = trim((string) ($c->shafafiya_nationality_code ?? ''))) !== '') {
                $byPrecedence[4][self::normalise($shafafiya)] ??= $id;
            }
        }

        // Weakest first, so stronger sources overwrite them.
        foreach ([4, 3, 2, 1] as $precedence) {
            foreach ($byPrecedence[$precedence] ?? [] as $alias => $id) {
                $this->nationalities[$alias] = $id;
            }
        }

        // Human decisions beat every derived rule.
        foreach (self::MANUAL_NATIONALITIES as $alias => $id) {
            $this->nationalities[$alias] = $id;
        }
    }

    /**
     * Lowercase, replace every non-alphanumeric run with a single space, trim.
     *
     * Not just TRIM(): live data contains "\t\nEmirati" — Emirati preceded by a
     * tab and a newline — which SQL TRIM does not touch. That row is why this
     * normalisation happens in PHP rather than in the query.
     */
    private static function normalise(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    // ── Row mapping ───────────────────────────────────────────────────────────

    private function mapPatient(object $r): array
    {
        $now = now()->toDateTimeString();
        [$full, $first, $middle, $last] = $this->mapNames($r);

        return [
            'id' => (int) $r->id,
            'nationality_id' => $this->resolveNationality($r),
            'title_id' => null,
            'gender_id' => $this->lookup('gender', $r->Gender ?? null),
            'marital_status_id' => $this->lookup('marital_status', $r->marital_status ?? null),
            'religion_id' => $this->lookup('religion', $r->religion ?? null),
            'document_presented_id' => null,
            'language_id' => $this->lookup('language', $r->language ?? null),
            'preferred_comm_language_id' => $this->lookup('comm_language', $r->pref_comm_lang ?? null),
            'membership_type_id' => $this->lookup('membership_type', $r->membership_type ?? null),
            'status_id' => $this->lookup('patient_status', $r->status ?? null),
            'chart' => (int) $r->Chart,
            'full_name' => $full,
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'nickname' => $this->clean($r->nick_name ?? null),
            'date_of_birth' => $this->parseDateOfBirth($r),
            'email' => $this->mapEmail($r),
            'city' => null,
            'emirates' => null,
            'created_at' => $this->parseLegacyDateTime($r->created_date ?? null) ?? $now,
            'updated_at' => $this->parseLegacyDateTime($r->updated_date ?? null) ?? $now,
        ];
    }

    /**
     * `full_name` is always populated and is never guessed at. The structured
     * parts are filled only where legacy actually holds them.
     *
     * 5,540 of 10,574 patients have ONLY the full string. The previous importer
     * split those with explode(' ') and wrote the literal "Unknown" when it
     * could not — turning a missing value into a fake one. Names are left
     * un-cased here too: ucwords() would turn McDonald into Mcdonald.
     *
     * @return array{0: string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function mapNames(object $r): array
    {
        $first = $this->clean($r->first_name ?? null);
        $middle = $this->clean($r->middle_name ?? null);
        $last = $this->clean($r->last_name ?? null);
        $full = $this->clean($r->Patient_name ?? null);

        if ($full === null) {
            $full = trim(implode(' ', array_filter([$first, $middle, $last]))) ?: null;
        }

        if ($full === null) {
            $full = 'Unnamed patient '.$r->id;
            $this->skip($r->id, 'no name in any column');
        }

        return [$full, $first, $middle, $last];
    }

    /**
     * Slash dates are d/m/Y. See the DOB_SENTINEL comment for the proof.
     * The 01/01/1900 sentinel becomes NULL: "unknown" is what NULL means, and
     * storing a real date for it is how the previous system ended up with 1,479
     * patients apparently born in 1900.
     */
    private function parseDateOfBirth(object $r): ?string
    {
        $raw = trim((string) ($r->Date_of_birth ?? ''));

        if ($raw === '' || $raw === self::DOB_SENTINEL) {
            return null;
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
            try {
                $date = Carbon::createFromFormat('d/m/Y', $raw)->startOfDay();
            } catch (\Throwable) {
                $this->skip($r->id, "unparseable date_of_birth '{$raw}'");

                return null;
            }

            // createFromFormat is forgiving — 31/02/2000 rolls into March.
            if ((int) $m[1] !== $date->day || (int) $m[2] !== $date->month) {
                $this->skip($r->id, "impossible date_of_birth '{$raw}'");

                return null;
            }

            return $date->toDateString();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $parsed = $this->parseLegacyDate($raw);

            // 0001-01-01 is a valid date and a nonsense birth date. See DOB_FLOOR.
            if ($parsed !== null && $parsed < self::DOB_FLOOR) {
                $this->skip($r->id, "sentinel date_of_birth '{$raw}' — stored as unknown");

                return null;
            }

            return $parsed;
        }

        $this->skip($r->id, "unrecognised date_of_birth format '{$raw}'");

        return null;
    }

    /** 124 legacy emails are junk: "none" x92, "n/a", "NA", a phone number. */
    private function mapEmail(object $r): ?string
    {
        $email = $this->clean($r->Email ?? null);

        if ($email === null) {
            return null;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->skip($r->id, "invalid email '{$email}' — not imported");

            return null;
        }

        return mb_strtolower($email);
    }

    private function resolveNationality(object $r): ?int
    {
        $raw = trim((string) ($r->Nationality ?? ''));

        if ($raw === '' || $raw === '0') {
            return null;
        }

        $id = $this->nationalities[self::normalise($raw)] ?? null;

        if ($id === null) {
            $this->skip($r->id, "unmapped nationality '{$raw}'");
        }

        return $id;
    }

    // ── Child rows ────────────────────────────────────────────────────────────

    /** @param array<int, object> $rows legacy id => row */
    private function importContactNumbers(array $rows): void
    {
        $batch = [];
        $now = now()->toDateTimeString();

        foreach ($rows as $id => $r) {
            foreach (self::PHONE_COLUMNS as $column => $type) {
                [$countryId, $number] = $this->parsePhone($r->{$column} ?? null);

                if ($number === null) {
                    continue;
                }

                $typeId = $this->lookups['contact_type'][$type] ?? null;

                if ($typeId === null) {
                    continue;
                }

                $batch[] = [
                    'patient_id' => $id,
                    'contact_type_id' => $typeId,
                    'country_id' => $countryId,
                    'contact_number' => $number,
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // (patient_id, contact_type_id) is the natural key — no crosswalk needed.
        $this->upsertBatch('patient_contact_numbers', $batch, ['patient_id', 'contact_type_id'], [
            'country_id', 'contact_number', 'active', 'updated_at',
        ]);
    }

    /**
     * contact_person + relationship + phone2 are ONE entity, so they are one row.
     * Measured: not a single legacy row has phone2 without a contact_person, and
     * 138 have a name with no number — which is why the number is nullable here
     * and why this is not a patient_contact_numbers row.
     *
     * @param array<int, object> $rows
     */
    private function importEmergencyContacts(array $rows): void
    {
        $now = now()->toDateTimeString();
        $wanted = [];

        foreach ($rows as $id => $r) {
            $name = $this->clean($r->contact_person ?? null);

            if ($name === null) {
                continue;
            }

            [$countryId, $number] = $this->parsePhone($r->phone2 ?? null);

            $wanted[$id] = [
                'patient_id' => $id,
                'relationship_id' => $this->lookup('relationship', $r->relationship ?? null),
                'country_id' => $countryId,
                'name' => $name,
                'mobile_number' => $number,
                'active' => true,
                'updated_at' => $now,
            ];
        }

        $this->writeOnePerPatient('patient_emergency_contacts', $wanted, $now);
    }

    /**
     * The insurance block. `package_name` and `ins_pol_num` are overloaded and
     * mean different things per payer — measured: package_name is filled only
     * for payer 1 (Thiqa, 1,068 rows) and payer 4 (NextCare, 95).
     *
     * @param array<int, object> $rows
     */
    private function importPayers(array $rows): void
    {
        $now = now()->toDateTimeString();
        $wanted = [];

        foreach ($rows as $id => $r) {
            $raw = trim((string) ($r->Insurance_Company ?? ''));

            if ($raw === '' || $raw === '0') {
                continue;
            }

            if (! ctype_digit($raw) || ! isset($this->payerIds[(int) $raw])) {
                $this->skip($id, "unknown Insurance_Company '{$raw}'");

                continue;
            }

            $payerId = (int) $raw;
            $policyId = $this->resolvePolicy($r, $payerId);
            [$packageId, $thirdPartyId] = $this->resolvePackageOrThirdParty($r, $payerId);
            [$expiry, $expiryUnknown] = $this->parseCardExpiry($r);

            $wanted[$id] = [
                'patient_id' => $id,
                'payer_id' => $payerId,
                'payer_policy_id' => $policyId,
                'payer_package_id' => $packageId,
                'payer_third_party_id' => $thirdPartyId,
                'insurance_number' => $this->clean($r->Insurance_Card_Number ?? null),
                'reimbursement_card_name' => $this->clean($r->Reimbursment_Insurance_Card_Name ?? null),
                'expiry_date' => $expiry,
                'expiry_unknown' => $expiryUnknown,
                'coverage_percent' => $policyId !== null ? $this->policyCover($policyId) : null,
                'is_primary' => true,
                'active' => true,
                'updated_at' => $now,
            ];
        }

        $this->writeOnePerPatient('patient_payers', $wanted, $now);
    }

    private function resolvePolicy(object $r, int $payerId): ?int
    {
        $raw = trim((string) ($r->insurance_plan ?? ''));

        if ($raw === '' || $raw === '0' || ! ctype_digit($raw)) {
            return null;
        }

        // The plan must belong to the payer the patient is actually on.
        return ($this->policyIds[(int) $raw] ?? null) === $payerId ? (int) $raw : null;
    }

    /**
     * Thiqa  -> package_name / ins_pol_num hold a TIER as text
     * NextCare -> package_name holds an insurance_sub id (24 = Orient PJSC)
     * others -> ins_pol_num holds an insurance_sub id
     * '0' (5,323 rows in ins_pol_num) is a placeholder, not a value.
     *
     * @return array{0: ?int, 1: ?int} [package id, third party id]
     */
    private function resolvePackageOrThirdParty(object $r, int $payerId): array
    {
        $package = trim((string) ($r->package_name ?? ''));
        $policyNum = trim((string) ($r->ins_pol_num ?? ''));

        // Thiqa: both columns carry the tier, in seven spellings between them.
        $fromPackage = LegacyPayerImporter::canonicalPackageName($package);
        $fromPolicy = LegacyPayerImporter::canonicalPackageName($policyNum);

        if ($fromPackage !== null || $fromPolicy !== null) {
            if ($fromPackage !== null && $fromPolicy !== null && $fromPackage !== $fromPolicy) {
                $this->skip($r->id, "package tier conflict: '{$package}' vs '{$policyNum}'");
            }

            $packageId = $this->packageIds[$fromPackage ?? $fromPolicy] ?? null;

            // A package belongs to ONE payer. Three patients on SelfPay and
            // FAZAA still carry 'Thiqa 2' in ins_pol_num — stale text left over
            // from a payer change that was never cleared. Attaching a Thiqa tier
            // to a self-pay patient would be inventing cover they do not have.
            if ($packageId !== null && ($this->packageOwners[$packageId] ?? null) !== $payerId) {
                $this->skip($r->id, "'{$fromPackage}{$fromPolicy}' is a package of another payer — ignored for payer {$payerId}");

                return [null, null];
            }

            return [$packageId, null];
        }

        // Otherwise a numeric value in either column MAY be an insurance_sub id.
        //
        // It is only trusted when that third party actually belongs to the
        // patient's payer. Legacy has 15 rows where it does not: Daman and ADNIC
        // patients carrying ins_pol_num '3' or '8', which are NextCare
        // sub-payers ("Al-Ain Ahlia", "Al Sagr"). A Daman patient is not
        // insured by a NextCare TPA — in those rows the number means something
        // else entirely, so it is left unmapped rather than guessed.
        foreach ([$package, $policyNum] as $candidate) {
            if ($candidate === '' || $candidate === '0' || ! ctype_digit($candidate)) {
                continue;
            }

            $id = (int) $candidate;

            if (($this->thirdPartyOwners[$id] ?? null) === $payerId) {
                return [null, $id];
            }

            if (isset($this->thirdPartyOwners[$id])) {
                $this->skip($r->id, "third party {$id} belongs to payer {$this->thirdPartyOwners[$id]}, not {$payerId} — ignored");
            }
        }

        return [null, null];
    }

    /** Legacy uses 0001-01-01 for "expiry unknown" — 41 rows. */
    private function parseCardExpiry(object $r): array
    {
        $raw = trim((string) ($r->Insurance_Card_Expiry ?? ''));

        if ($raw === '' || str_starts_with($raw, '0001-01-01')) {
            return [null, $raw !== ''];
        }

        return [$this->parseLegacyDate($raw), false];
    }

    private function policyCover(int $policyId): ?float
    {
        static $covers = null;

        $covers ??= $this->tenant()->table('payer_policies')->pluck('plan_cover', 'id')->toArray();

        return isset($covers[$policyId]) ? (float) $covers[$policyId] : null;
    }

    /**
     * Write at most one row per patient without constraining the table to one
     * row per patient forever. Legacy holds a single emergency contact and a
     * single insurance block, but the domain allows more, so idempotency is
     * handled by matching on patient_id rather than by a unique index.
     */
    private function writeOnePerPatient(string $table, array $wanted, string $now): void
    {
        if ($this->dryRun || empty($wanted)) {
            return;
        }

        $existing = $this->tenant()->table($table)
            ->whereIn('patient_id', array_keys($wanted))
            ->pluck('id', 'patient_id')->toArray();

        $insert = [];

        foreach ($wanted as $patientId => $attributes) {
            if (isset($existing[$patientId])) {
                $this->tenant()->table($table)->where('id', $existing[$patientId])->update($attributes);

                continue;
            }

            $insert[] = $attributes + ['created_at' => $now];
        }

        foreach (array_chunk($insert, 500) as $slice) {
            $this->tenant()->table($table)->insert($slice);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function lookup(string $type, mixed $raw): ?int
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return null;
        }

        // NULL, '' and '0' are three legacy encodings of "no membership".
        if ($type === 'membership_type' && $value === '0') {
            return null;
        }

        // Case-folded on both sides — see loadReferenceData() for why.
        $value = mb_strtolower($value);
        $value = self::VALUE_MAP[$type][$value] ?? $value;

        return $this->lookups[$type][$value] ?? null;
    }

    /** @return array{0: ?int, 1: ?string} [country id, digits-only subscriber number] */
    private function parsePhone(mixed $raw): array
    {
        $digits = preg_replace('/\D/', '', (string) ($raw ?? '')) ?? '';

        if ($digits === '') {
            return [null, null];
        }

        // Longest prefix first, so 971 is not mistaken for 97.
        $codes = array_keys($this->phoneCodes);
        rsort($codes, SORT_NUMERIC);

        foreach ($codes as $code) {
            if ($code !== '' && str_starts_with($digits, (string) $code)) {
                $rest = substr($digits, strlen((string) $code));

                // Require something left to dial. Legacy holds numbers that are
                // nothing BUT a country code ("971"), and splitting those leaves
                // an empty subscriber number — so they are junk, not a UAE number.
                if (strlen($rest) >= 5) {
                    return [$this->phoneCodes[$code], mb_substr($rest, 0, 20)];
                }
            }
        }

        // Nothing recognisable. Keep the digits verbatim with NO country, rather
        // than assuming the UAE — guessing a country here is what made "971"
        // round-trip as "971971" and "0" as "9710".
        return [null, mb_substr($digits, 0, 20)];
    }

    private function clean(mixed $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');

        return $value !== '' ? mb_substr($value, 0, 191) : null;
    }
}
