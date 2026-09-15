<?php

namespace App\Console\Legacy;

/**
 * legacy.insurance_company -> payers
 * legacy.insurance_plan    -> payer_policies
 * legacy.insurance_sub     -> payer_third_parties
 * derived from patient data -> payer_packages
 *
 * Primary keys are preserved throughout, the same convention LegacyStaffImporter
 * and LegacyUserImporter follow. That matters here more than usual: patient
 * rows reference a payer by raw id in a varchar column, so keeping the ids means
 * the patient import needs no translation step.
 *
 * `payer_packages` has no legacy table behind it. The Thiqa package tier lives
 * inside patient_registration.package_name as free text, so the tiers are
 * derived from the distinct values actually present and matched back by the
 * patient importer. That is why this importer runs first.
 */
class LegacyPayerImporter extends BaseLegacyImporter
{
    /**
     * Legacy writes the Thiqa tier in seven different spellings across two
     * columns — "Thiqa 1", " Thiqa 1", "Thiqa1", "Thiqa  1", "Thiqa 2",
     * "thiqa 2", " Thiqa 2", "Thiqa  2". Collapsing whitespace and lowercasing
     * folds all of them onto these two canonical names.
     */
    private const THIQA_PAYER_ID = 1;

    public function label(): string
    {
        return 'payers';
    }

    public function run(): void
    {
        $payerIds = $this->importPayers();
        $this->importPolicies($payerIds);
        $this->importThirdParties($payerIds);
        $this->importPackages($payerIds);
    }

    /** @return array<int, true> the payer ids that now exist, for FK validation */
    private function importPayers(): array
    {
        $batch = [];
        $now = now()->toDateTimeString();

        foreach ($this->legacy()->table('insurance_company')->orderBy('insurance_company_id')->get() as $row) {
            $this->read++;

            $name = trim((string) ($row->insurance_company_name ?? ''));

            if ($name === '') {
                $this->skip($row->insurance_company_id, 'blank insurance_company_name — skipped');

                continue;
            }

            $batch[] = [
                'id' => (int) $row->insurance_company_id,
                'name' => $name,
                'display_name' => trim((string) ($row->ins_display_name ?? '')) ?: null,
                'payer_code' => trim((string) ($row->payer ?? '')) ?: null,
                'receiver_code' => trim((string) ($row->receiver ?? '')) ?: null,
                'malaffi_id' => ($row->malaffi_idval ?? null) ?: null,
                // Legacy is_insurance_company = 0 for SelfPay, Gift Voucher and
                // Reimbursement, which are payers but not insurers.
                'is_insurance' => (int) ($row->is_insurance_company ?? 0) === 1,
                'colour' => trim((string) ($row->insurance_company_color ?? '')) ?: null,
                // Legacy controlled ordering by prefixing the NAME ("01- Thiqa
                // Ins.Co."). The prefix stays in the name, but ordering is now a
                // real column.
                'sort_order' => (int) ($row->insurance_order ?? 0),
                'active' => strtoupper(trim((string) ($row->status ?? ''))) === 'Y',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->upsertBatch('payers', $batch, ['id'], [
            'name', 'display_name', 'payer_code', 'receiver_code', 'malaffi_id',
            'is_insurance', 'colour', 'sort_order', 'active', 'updated_at',
        ]);

        $this->written += \count($batch);
        $this->info('payers: '.\count($batch));

        return array_fill_keys(array_column($batch, 'id'), true);
    }

    private function importPolicies(array $payerIds): void
    {
        $batch = [];
        $now = now()->toDateTimeString();

        foreach ($this->legacy()->table('insurance_plan')->orderBy('plan_id')->get() as $row) {
            $this->read++;

            $payerId = $this->validId($row->plan_insuranceid ?? null, $payerIds);

            if ($payerId === null) {
                $this->skip($row->plan_id, "plan_insuranceid [{$row->plan_insuranceid}] is not a known payer");

                continue;
            }

            $batch[] = [
                'id' => (int) $row->plan_id,
                'payer_id' => $payerId,
                'name' => trim((string) ($row->plan_name ?? '')) ?: 'Unnamed plan',
                // Legacy stores this as a double fraction (1.00 = 100%,
                // 0.10 = 10%). Kept as a fraction in decimal(5,4) so copay
                // arithmetic cannot drift.
                'plan_cover' => $row->plan_cover !== null ? (float) $row->plan_cover : null,
                'policy_order' => 0,
                'is_insurance' => true,
                'active' => strtoupper(trim((string) ($row->plan_status ?? ''))) === 'Y',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->upsertBatch('payer_policies', $batch, ['id'], [
            'payer_id', 'name', 'plan_cover', 'is_insurance', 'active', 'updated_at',
        ]);

        $this->written += \count($batch);
        $this->info('payer_policies: '.\count($batch));
    }

    private function importThirdParties(array $payerIds): void
    {
        $batch = [];
        $now = now()->toDateTimeString();

        foreach ($this->legacy()->table('insurance_sub')->orderBy('id')->get() as $row) {
            $this->read++;

            $payerId = $this->validId($row->ins_id ?? null, $payerIds);

            if ($payerId === null) {
                $this->skip($row->id, "ins_id [{$row->ins_id}] is not a known payer");

                continue;
            }

            $batch[] = [
                'id' => (int) $row->id,
                'payer_id' => $payerId,
                'tpa_id' => trim((string) ($row->ins_sub_id ?? '')) ?: null,
                'name' => trim((string) ($row->name ?? '')) ?: 'Unnamed third party',
                'active' => (int) ($row->active ?? 0) === 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->upsertBatch('payer_third_parties', $batch, ['id'], [
            'payer_id', 'tpa_id', 'name', 'active', 'updated_at',
        ]);

        $this->written += \count($batch);
        $this->info('payer_third_parties: '.\count($batch));
    }

    /**
     * Derive the Thiqa package tiers from the patient data itself.
     *
     * There is no legacy table for these. `package_name` and `ins_pol_num` hold
     * the tier as free text when the payer is Thiqa, so the canonical set is
     * whatever normalises out of those two columns.
     */
    private function importPackages(array $payerIds): void
    {
        if (! isset($payerIds[self::THIQA_PAYER_ID])) {
            $this->skip('payer '.self::THIQA_PAYER_ID, 'Thiqa payer missing — package tiers not derived');

            return;
        }

        $names = [];

        foreach (['package_name', 'ins_pol_num'] as $column) {
            $values = $this->legacy()->table('patient_registration')
                ->where('Insurance_Company', (string) self::THIQA_PAYER_ID)
                ->whereNotNull($column)
                ->distinct()
                ->pluck($column);

            foreach ($values as $value) {
                if ($canonical = self::canonicalPackageName($value)) {
                    $names[$canonical] = true;
                }
            }
        }

        $now = now()->toDateTimeString();
        $batch = [];

        foreach (array_keys($names) as $name) {
            $batch[] = [
                'payer_id' => self::THIQA_PAYER_ID,
                'name' => $name,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->upsertBatch('payer_packages', $batch, ['payer_id', 'name'], ['active', 'updated_at']);

        $this->written += \count($batch);
        $this->info('payer_packages: '.\count($batch).' ('.implode(', ', array_keys($names)).')');
    }

    /**
     * Fold a legacy tier spelling onto its canonical name, or null if it is not
     * a tier at all. " thiqa  1" / "Thiqa1" / "THIQA 1" all become "Thiqa 1".
     *
     * Shared with LegacyPatientImporter, which has to match a patient's raw
     * value back to the row this importer created.
     */
    public static function canonicalPackageName(mixed $raw): ?string
    {
        $value = strtolower(trim((string) $raw));
        $value = preg_replace('/\s+/', '', $value) ?? '';

        return match (true) {
            $value === 'thiqa1' => 'Thiqa 1',
            $value === 'thiqa2' => 'Thiqa 2',
            default => null,
        };
    }
}
