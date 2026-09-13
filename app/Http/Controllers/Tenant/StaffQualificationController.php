<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Staff;
use App\Models\Tenant\StaffQualification;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffQualificationController extends Controller
{
    public function index(Request $request)
    {
        $qualifications = StaffQualification::with('staff:id,name')
            ->orderBy('expires_on')
            ->get()
            ->map(fn (StaffQualification $q) => $this->present($q));

        return response()->json(['qualifications' => $qualifications]);
    }

    public function show(Request $request, int $qualificationId)
    {
        $qualification = StaffQualification::with('staff:id,name')->findOrFail($qualificationId);

        return response()->json(['qualification' => $this->present($qualification)]);
    }

    /** Compact staff picker for the create/edit form — id + name only, no CP.STAFF data. */
    public function staffOptions(Request $request)
    {
        return response()->json([
            'staff' => Staff::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, ['staff_id' => ['required', 'integer', 'exists:tenant.staff,id']]);

        $qualification = StaffQualification::create($data);

        AuditLog::record($request->user()->id, 'staff_qualification.created', StaffQualification::class, $qualification->id, [
            'staff_id' => $qualification->staff_id,
            'title' => $qualification->title,
        ]);

        return response()->json(['qualification' => $this->present($qualification->load('staff:id,name'))], 201);
    }

    public function update(Request $request, int $qualificationId)
    {
        $qualification = StaffQualification::findOrFail($qualificationId);
        $data = $this->validated($request);

        $qualification->update($data);

        AuditLog::record($request->user()->id, 'staff_qualification.updated', StaffQualification::class, $qualification->id, [
            'title' => $qualification->title,
            'status' => $qualification->status,
        ]);

        return response()->json(['qualification' => $this->present($qualification->fresh()->load('staff:id,name'))]);
    }

    public function destroy(Request $request, int $qualificationId)
    {
        $qualification = StaffQualification::findOrFail($qualificationId);
        $qualification->delete();

        AuditLog::record($request->user()->id, 'staff_qualification.deleted', StaffQualification::class, $qualificationId, [
            'staff_id' => $qualification->staff_id,
            'title' => $qualification->title,
        ]);

        return response()->json(['message' => 'Qualification deleted.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, array $extra = []): array
    {
        $data = $request->validate($extra + [
            'title' => ['required', 'string', 'max:255'],
            'issuing_org' => ['nullable', 'string', 'max:255'],
            'credential_no' => ['nullable', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'expired', 'revoked'])],
        ]);

        if (! empty($data['issued_on']) && ! empty($data['expires_on']) && $data['expires_on'] < $data['issued_on']) {
            throw ValidationException::withMessages([
                'expires_on' => ['The expiry date must be on or after the issue date.'],
            ]);
        }

        return $data;
    }

    private function present(StaffQualification $qualification): array
    {
        return [
            'id' => $qualification->id,
            'staff' => $qualification->staff,
            'title' => $qualification->title,
            'issuing_org' => $qualification->issuing_org,
            'credential_no' => $qualification->credential_no,
            'issued_on' => $qualification->issued_on,
            'expires_on' => $qualification->expires_on,
            'status' => $qualification->status,
        ];
    }
}
