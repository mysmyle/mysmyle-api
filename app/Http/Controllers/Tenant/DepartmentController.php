<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Department;
use App\Services\DepartmentService;
use Illuminate\Http\Request;

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

        return response()->json(['department' => $department], 201);
    }
}
