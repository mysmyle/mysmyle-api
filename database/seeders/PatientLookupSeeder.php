<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Every enumerable value the patient module needs.
 *
 * `legacy_value` is the EXACT string legacy wrote, so the importer resolves
 * deterministically instead of pattern-matching display labels. The counts in
 * the comments are measured row counts from the legacy patient table, so the
 * importer can assert it mapped the expected volume and fail loudly if not.
 *
 * Note the alias rows (ENG_ALIAS, and the Thiqa tier spellings): legacy holds
 * more than one string meaning the same thing, and a lookup row per spelling is
 * how that folds without any string-munging in application code.
 */
class PatientLookupSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [];

        $add = function (string $type, string $code, string $name, ?string $legacy = null,
            int $sort = 0, bool $isPhi = false) use (&$rows, $now) {
            $rows[] = [
                'type' => $type,
                'code' => $code,
                'name' => $name,
                'legacy_value' => $legacy,
                'is_phi' => $isPhi,
                'sort_order' => $sort,
                'is_system' => true,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        };

        // ─── title ───────────────────────────────────────────────────────────
        $add('title', 'MR', 'Mr.', 'MR', 1);
        $add('title', 'MRS', 'Mrs.', 'MRS', 2);
        $add('title', 'MISS', 'Miss.', 'MISS', 3);
        $add('title', 'MS', 'Ms.', 'MS', 4);
        $add('title', 'DR', 'Dr.', 'DR', 5);

        // ─── gender ── legacy Gender: Male 6,077 / Female 4,178 / Other 2 / '0' 306
        $add('gender', 'male', 'Male', 'Male', 1);
        $add('gender', 'female', 'Female', 'Female', 2);
        $add('gender', 'other', 'Other', 'Other', 3);
        // The 306 rows holding the literal string '0' — an unvalidated <select>
        // placeholder that was saved as if it were a choice.
        $add('gender', 'unknown', 'Unknown', '0', 4);

        // ─── language (spoken) ── legacy `language`, 41.2% filled
        $add('language', 'ARA', 'Arabic', 'ARA', 1);
        $add('language', 'ENG', 'English', 'ENG', 2);
        $add('language', 'TGL', 'Tagalog', 'TGL', 3);
        $add('language', 'HIN', 'Hindi', 'HIN', 4);
        // 'ENGL' (19 rows) is a typo for ENG. An alias row rather than a special
        // case in code — the importer just looks up legacy_value.
        $add('language', 'ENG_ALIAS', 'English (legacy ENGL)', 'ENGL', 99);

        // ─── comm_language ── legacy pref_comm_lang, 100% filled, 2 values
        $add('comm_language', 'ARA', 'Arabic', 'ARA', 1);   // 6,730
        $add('comm_language', 'ENG', 'English', 'ENG', 2);  // 3,833

        // ─── marital_status ── legacy: M 2,463 / S 1,831
        $add('marital_status', 'single', 'Single', 'S', 1);
        $add('marital_status', 'married', 'Married', 'M', 2);
        $add('marital_status', 'divorced', 'Divorced', null, 3);
        $add('marital_status', 'widowed', 'Widowed', null, 4);

        // ─── religion ── legacy: MOS 3,169 / CHR 709 / OTH 219 / HIN 91
        $add('religion', 'MOS', 'Muslim', 'MOS', 1);
        $add('religion', 'CHR', 'Christian', 'CHR', 2);
        $add('religion', 'HIN', 'Hindu', 'HIN', 3);
        $add('religion', 'OTH', 'Other', 'OTH', 4);

        // ─── relationship ── emergency contact; legacy rendered these from a
        // PHP array literal on the registration page
        $add('relationship', 'PAR', 'Parent', 'PAR', 1);    // 1,261
        $add('relationship', 'SPO', 'Spouse', 'SPO', 2);    // 1,246
        $add('relationship', 'FND', 'Friend', 'FND', 3);    //   485
        $add('relationship', 'SIB', 'Sibling', 'SIB', 4);   //   454
        $add('relationship', 'GRD', 'Guardian', 'GRD', 5);  //   248

        // ─── membership_type ── legacy: blue 2,035 / silver 1,562 / gold 1,376
        //     bronze 30 / platinum 13. NULL, '' and '0' ALL mean "no membership"
        //     — three encodings of the same absence, which is why the importer
        //     maps all three to NULL rather than to a lookup row.
        $add('membership_type', 'bronze', 'Bronze', 'bronze', 1);
        $add('membership_type', 'blue', 'Blue', 'blue', 2);
        $add('membership_type', 'silver', 'Silver', 'silver', 3);
        $add('membership_type', 'gold', 'Gold', 'gold', 4);
        $add('membership_type', 'platinum', 'Platinum VIP', 'platinum', 5);

        // ─── patient_status ── legacy `status`: Y 10,549 / N 13 / NULL 1
        $add('patient_status', 'active', 'Active', 'Y', 1);
        $add('patient_status', 'inactive', 'Inactive', 'N', 2);
        $add('patient_status', 'archived', 'Archived', null, 3);
        // Set on the losing record of a merge.
        $add('patient_status', 'merged', 'Merged', null, 4);

        // ─── contact_type ── legacy had four phone columns with overlapping
        //     meaning. phone2 is NOT here: its own column comment says it acts
        //     as the emergency contact number, so it becomes an emergency
        //     contact record instead.
        $add('contact_type', 'mobile', 'Mobile', 'Patient_mobile_phone1', 1);
        $add('contact_type', 'mobile_2', 'Secondary mobile', 'Patient_mobile_phone2', 2);
        $add('contact_type', 'whatsapp', 'WhatsApp', 'pt_whatsapp', 3);
        $add('contact_type', 'home', 'Home', null, 4);
        $add('contact_type', 'work', 'Work', null, 5);

        // ─── identity_document_type ──
        $add('identity_document_type', 'emirates_id', 'Emirates ID', 'EID_num', 1);
        $add('identity_document_type', 'passport', 'Passport', 'pass_num', 2);
        $add('identity_document_type', 'visa', 'Visa', null, 3);
        $add('identity_document_type', 'gcc_id', 'GCC ID', null, 4);
        $add('identity_document_type', 'birth_certificate', 'Birth certificate', null, 5);

        // ─── consent_type ──
        $add('consent_type', 'general_consent', 'General consent', 'general_consent', 1);
        $add('consent_type', 'insurance_release', 'Insurance release form', 'Insurance_release_form', 2);
        $add('consent_type', 'privacy_notice', 'Privacy notice', null, 3);
        $add('consent_type', 'marketing', 'Marketing contact', null, 4);

        // ─── note_type ──
        $add('note_type', 'patient', 'Patient note', 'patient_notes', 1);
        $add('note_type', 'rap', 'RAP note', null, 2);
        $add('note_type', 'clinic', 'Clinic note', null, 3);
        $add('note_type', 'registration', 'Registration note', null, 4);

        // ─── package_tier ── legacy package_name / ins_pol_num when the payer is
        //     Thiqa. Legacy spellings, all of which occur: 'Thiqa 1', ' Thiqa 1',
        //     'Thiqa1', 'Thiqa 2', 'thiqa 2', ' Thiqa 2', 'Thiqa  2'. The
        //     importer trims, collapses whitespace and lowercases before
        //     matching legacy_value, so one row per real tier is enough.
        $add('package_tier', 'thiqa_1', 'Thiqa 1', 'thiqa 1', 1);
        $add('package_tier', 'thiqa_2', 'Thiqa 2', 'thiqa 2', 2);
        $add('package_tier', 'tc_plus_3', 'TC Plus 3', 'tc plus 3', 3);

        // ─── preference_key ── replaces the ten persona_* TEXT columns that
        //     legacy carried on all 10,563 rows to hold 46 values in total.
        //     is_phi drives whether the value is encrypted on the model.
        $add('preference_key', 'socio_economics', 'Socio-economics', 'persona_socio_economics', 1);
        $add('preference_key', 'payment_preference', 'Payment preference', 'persona_payment_preference', 2);
        $add('preference_key', 'preferred_name', 'Preferred name', 'persona_preferred_name', 3, true);
        $add('preference_key', 'tribe_name', 'Tribe name', 'persona_tribe_name', 4, true);
        $add('preference_key', 'preferred_hobby', 'Preferred hobby', 'persona_preferred_hobby', 5, true);
        $add('preference_key', 'preferred_drink', 'Preferred drink', 'persona_preferred_drink', 6, true);
        $add('preference_key', 'communication_preference', 'Communication preference', 'persona_communication_preference', 7);
        $add('preference_key', 'personality_preference', 'Personality preference', 'persona_personality_preference', 8);
        $add('preference_key', 'preferred_appointment_day', 'Preferred appointment day', 'persona_preferred_appointment_day', 9);
        $add('preference_key', 'preferred_appointment_time', 'Preferred appointment time', 'persona_preferred_appointment_time', 10);

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('patient_lookups')->upsert(
                $chunk,
                ['type', 'code'],
                ['name', 'legacy_value', 'is_phi', 'sort_order', 'is_system', 'active', 'updated_at']
            );
        }

        // The test charts that lived as a PHP array literal inside legacy
        // getChartNew(). Held as data so they are visible and auditable — and so
        // the allocator can never hand one out once this app becomes the writer.
        $reserved = [
            9000009, 8000008, 7000007, 6000006, 5000005, 4000004, 3000003,
            2000002, 2075602, 1000005, 1000004, 1000003, 1000002, 1000001,
            777777, 111111,
        ];

        DB::table('reserved_chart_numbers')->upsert(
            array_map(fn ($c) => [
                'chart' => $c,
                'reason' => 'legacy test chart',
                'created_at' => $now,
                'updated_at' => $now,
            ], $reserved),
            ['chart'],
            ['reason', 'updated_at']
        );
    }
}
