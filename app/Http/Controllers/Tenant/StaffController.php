<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Staff;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $staff = Staff::withCount('users')
            ->orderBy('name')
            ->get(['id', 'name', 'personal_email', 'gender', 'date_of_birth', 'status']);

        return response()->json(['staff' => $staff]);
    }

    // Note: tenant models are looked up in the controller, not via route-model
    // binding — SubstituteBindings runs before the `tenant` connection is bound.
    public function show(Request $request, int $staffId)
    {
        $staff = Staff::findOrFail($staffId);

        return response()->json(['staff' => $this->present($staff)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'personal_email' => ['required', 'email', 'max:255'],
            'gender' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
        ]);

        // New staff are always created active — status is not accepted here.
        $staff = Staff::create([
            'name' => $data['name'],
            'personal_email' => $data['personal_email'],
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'status' => 'active',
        ]);

        AuditLog::record($request->user()->id, 'staff.created', Staff::class, $staff->id, [
            'name' => $staff->name,
        ]);

        return response()->json(['staff' => $staff], 201);
    }

    public function update(Request $request, int $staffId)
    {
        $staff = Staff::findOrFail($staffId);
        $previousStatus = $staff->status;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'personal_email' => ['required', 'email', 'max:255'],
            'gender' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $staff->update([
            'name' => $data['name'],
            'personal_email' => $data['personal_email'],
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'status' => $data['status'],
        ]);

        AuditLog::record($request->user()->id, 'staff.updated', Staff::class, $staff->id, [
            'status' => ['from' => $previousStatus, 'to' => $data['status']],
        ]);

        return response()->json(['staff' => $this->present($staff)]);
    }

    private function present(Staff $staff): array
    {
        return $staff->only(['id', 'name', 'personal_email', 'gender', 'date_of_birth', 'status']);
    }
}
