<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientContactNumber;
use App\Models\Tenant\PatientPayer;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    /** Results the desk can actually scan; a wider net means a better query, not more rows. */
    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 100;

    /**
     * The front-desk patient lookup behind RAP's Search Patient modal: one box
     * that takes a chart number, a phone number or a name (see
     * Patient::scopeMatching for how the term is routed).
     *
     * Deliberately not paginated. This answers "which patient is on the phone",
     * where the right response to 200 hits is a narrower term, not page 2 —
     * so it returns one capped page and says whether it capped, which is what
     * the modal shows ("Showing first 25 of more matches — refine your search").
     */
    public function search(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $term = trim($data['q']);
        $limit = (int) ($data['limit'] ?? self::DEFAULT_LIMIT);

        $patients = Patient::query()
            ->matching($term)
            ->orderedForSearch($term)
            // One extra row is the cheapest way to know there are more without
            // a second COUNT over the same LIKEs.
            ->limit($limit + 1)
            ->with([
                'gender:id,value,name',
                'contactNumbers:id,patient_id,contact_type_id,country_id,contact_number,active',
                'contactNumbers.contactType:id,value,name',
                'contactNumbers.country:id,phone_code',
                'payers' => fn ($q) => $q->where('active', true)->orderByDesc('is_primary'),
                'payers.payer:id,name,display_name,is_insurance',
            ])
            ->get();

        $hasMore = $patients->count() > $limit;

        return response()->json([
            'patients' => $patients->take($limit)->map(fn (Patient $patient) => $this->present($patient))->values(),
            'has_more' => $hasMore,
            'limit' => $limit,
        ]);
    }

    private function present(Patient $patient): array
    {
        $payer = $patient->primaryPayer();

        return [
            'id' => $patient->id,
            'chart' => $patient->chart,
            'full_name' => $patient->full_name,
            'gender' => $patient->gender?->name,
            'date_of_birth' => $patient->date_of_birth?->toDateString(),
            // Sent computed rather than derived in the browser: a date of birth
            // read against the client's clock disagrees with the clinic's for a
            // few hours a day, and the desk reads this out loud to confirm
            // identity.
            'age' => $patient->date_of_birth?->age,
            'email' => $patient->email,
            'mobile' => $this->presentNumber($patient->contactNumberOfType('primary')),
            'whatsapp' => $this->presentNumber($patient->contactNumberOfType('whatsapp')),
            'payer' => $payer ? $this->presentPayer($payer) : null,
        ];
    }

    /**
     * Digits only in `number`, with the country code beside it — the two are
     * stored apart, so the caller joins them however it wants to display them
     * and still has the raw subscriber number to dial or match on.
     */
    private function presentNumber(?PatientContactNumber $number): ?array
    {
        if (! $number) {
            return null;
        }

        return [
            'number' => $number->contact_number,
            'country_code' => $number->country?->phone_code,
        ];
    }

    private function presentPayer(PatientPayer $payer): array
    {
        return [
            'name' => $payer->payer?->label(),
            'is_insurance' => (bool) $payer->payer?->is_insurance,
            'insurance_number' => $payer->insurance_number,
            'expiry_date' => $payer->expiry_date?->toDateString(),
            'expired' => $payer->isExpired(),
        ];
    }
}
