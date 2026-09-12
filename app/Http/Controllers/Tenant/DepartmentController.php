<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Department;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Services\DepartmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'departments' => Department::all(['id', 'name']),
        ]);
    }

    public function store(Request $request, DepartmentService $service)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'unique:tenant.departments,name'],
        ]);

        $department = $service->createWithRoles($validated['name']);

        AuditLog::record($request->user()->id, 'department.created', Department::class, $department->id, [
            'name' => $department->name,
        ]);

        return response()->json(['department' => $department], 201);
    }

    public function update(Request $request, int $departmentId)
    {
        $department = Department::findOrFail($departmentId);
        $previousName = $department->name;

        $validated = $request->validate([
            'name' => ['required', 'string', Rule::unique('tenant.departments', 'name')->ignore($department->id)],
        ]);

        $department->update($validated);

        AuditLog::record($request->user()->id, 'department.updated', Department::class, $department->id, [
            'name' => ['from' => $previousName, 'to' => $department->name],
        ]);

        return response()->json(['department' => $department]);
    }

    /**
     * Deleting a department cascades to delete every role on it. Blocked while
     * any of those roles still has a user assigned — reassign them first.
     */
    public function destroy(Request $request, int $departmentId)
    {
        $department = Department::findOrFail($departmentId);
        $roleIds = Role::where('department_id', $department->id)->pluck('id');

        if (User::whereIn('role_id', $roleIds)->exists()) {
            throw ValidationException::withMessages([
                'department' => ['This department still has users assigned to its roles. Reassign them first.'],
            ]);
        }

        $department->delete();

        AuditLog::record($request->user()->id, 'department.deleted', Department::class, $departmentId, [
            'name' => $department->name,
        ]);

        return response()->json(['message' => 'Department deleted.']);
    }
}
