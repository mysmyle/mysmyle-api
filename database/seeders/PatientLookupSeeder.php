<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Every enumerable value the patient module needs.
 *
 * Values follow the mysmyleerp reference build exactly — title, gender,
 * document_presented, membership_type, relationship, language, religion,
 * marital_status — plus three types legacy fills on every patient row that the
 * reference has not needed yet (comm_language, patient_status, contact_type).
 *
 * `value` is deliberately the string legacy already uses ('MR', 'ARA', 'PAR',
 * 'M', 'blue'), so for most fields the import is a direct match and no
 * translation table is required. The handful that DO need translating — the
 * literal '0' placeholders and the 'ENGL' typo — are handled by constant maps
 * in LegacyPatientImporter, because that is import logic and does not belong in
 * a table the clinic will still be using in five years.
 *
 * Counts are measured from the legacy patient table so the importer can assert
 * it mapped the expected volume and fail loudly if it did not.
 */
class PatientLookupSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [];

        $add = function (string $type, string $value, string $name, int $sort = 0) use (&$rows, $now) {
            $rows[] = [
                'type' => $type,
                'value' => $value,
                'name' => $name,
                'sort_order' => $sort,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        };

        // ─── title ───────────────────────────────────────────────────────────
        $add('title', 'MR', 'Mr.', 1);
        $add('title', 'MRS', 'Mrs.', 2);
        $add('title', 'MISS', 'Miss.', 3);
        $add('title', 'MS', 'Ms.', 4);

        // ─── gender ── legacy: Male 6,077 / Female 4,178 / Other 2 / '0' 306
        $add('gender', 'Male', 'Male', 1);
        $add('gender', 'Female', 'Female', 2);
        $add('gender', 'Other', 'Other', 3);
        // Beyond the reference: 306 legacy rows hold the literal string '0', an
        // unvalidated <select> placeholder saved as though it were a choice.
        // Without a row to land on, those patients import as NULL and the fact
        // that legacy recorded anything at all is lost.
        $add('gender', 'Unknown', 'Unknown', 4);

        // ─── document_presented ── which ID the patient produced at the desk
        $add('document_presented', 'eid', 'Emirates ID', 1);
        $add('document_presented', 'passport', 'Passport Non-Medical Tourist', 2);
        $add('document_presented', 'passport-none', 'Passport Medical Tourist', 3);

        // ─── membership_type ── legacy: blue 2,035 / silver 1,562 / gold 1,376
        //     bronze 30 / platinum 13. NULL, '' and '0' ALL mean "no membership"
        //     — three encodings of one absence, so the importer maps all three
        //     to NULL rather than inventing a lookup row for them.
        $add('membership_type', 'blue', 'Blue', 1);
        $add('membership_type', 'bronze', 'Bronze', 2);
        $add('membership_type', 'silver', 'Silver', 3);
        $add('membership_type', 'gold', 'Gold', 4);
        $add('membership_type', 'platinum', 'Platinum VIP', 5);

        // ─── relationship ── emergency contact; legacy rendered these from a
        //     PHP array literal on the registration page
        $add('relationship', 'PAR', 'Parent', 1);    // 1,261
        $add('relationship', 'SPO', 'Spouse', 2);    // 1,246
        $add('relationship', 'FND', 'Friend', 3);    //   485
        $add('relationship', 'SIB', 'Siblings', 4);  //   454
        $add('relationship', 'GRD', 'Guardian', 5);  //   248

        // ─── language (spoken) ── legacy `language`, 41.2% filled.
        //     The typo 'ENGL' (19 rows) folds to ENG in the importer.
        $add('language', 'ARA', 'Arabic', 1);
        $add('language', 'ENG', 'English', 2);
        $add('language', 'HIN', 'Indian', 3);
        $add('language', 'TGL', 'Tagalog', 4);

        // ─── religion ── legacy: MOS 3,169 / CHR 709 / OTH 219 / HIN 91
        $add('religion', 'MOS', 'Muslim', 1);
        $add('religion', 'CHR', 'Christian', 2);
        $add('religion', 'HIN', 'Hindu', 3);
        $add('religion', 'OTH', 'Other', 4);

        // ─── marital_status ── legacy: M 2,463 / S 1,831
        $add('marital_status', 'M', 'Married', 1);
        $add('marital_status', 'S', 'Single', 2);

        // ─── comm_language ── beyond the reference. Legacy `pref_comm_lang` is
        //     100% filled (ARA 6,730 / ENG 3,833) and decides which language the
        //     clinic messages the patient in. Distinct from `language`, which is
        //     what they speak.
        $add('comm_language', 'ARA', 'Arabic', 1);
        $add('comm_language', 'ENG', 'English', 2);

        // ─── patient_status ── beyond the reference. Legacy `status` is 100%
        //     filled (Y 10,549 / N 13) and is how a patient is retired without
        //     being deleted.
        $add('patient_status', 'active', 'Active', 1);
        $add('patient_status', 'inactive', 'Inactive', 2);

        // ─── contact_type ── the PATIENT'S OWN numbers only.
        //     There is deliberately no `emergency` type: legacy phone2 is the
        //     emergency contact's number and belongs to that contact, alongside
        //     their name and relationship, in patient_emergency_contacts.
        //     Measured: not one legacy row has an emergency number without a
        //     named contact attached to it.
        $add('contact_type', 'primary', 'Primary mobile', 1);
        $add('contact_type', 'whatsapp', 'WhatsApp', 2);
        $add('contact_type', 'alternative', 'Alternative mobile', 3);
        $add('contact_type', 'home', 'Home', 4);
        $add('contact_type', 'work', 'Work', 5);

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('patient_lookups')->upsert(
                $chunk,
                ['type', 'value'],
                ['name', 'sort_order', 'active', 'updated_at']
            );
        }
    }
}
