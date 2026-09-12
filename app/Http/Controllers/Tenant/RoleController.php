<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Role;
use App\Models\Tenant\RoleModuleAccess;
use App\Models\Tenant\User;
use App\Services\ModuleAccessService;
use App\Services\UserRoleService;
use App\Support\TenantCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        // Each role serializes its designation via the model's appended accessor.
        return response()->json([
            'roles' => Role::with('department')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:tenant.departments,id'],
            'designation_id' => ['required', 'integer'],
        ]);

        $exists = Role::where('department_id', $validated['department_id'])
            ->where('designation_id', $validated['designation_id'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'designation_id' => ['This department and designation combination already exists as a role.'],
            ]);
        }

        $role = Role::create($validated)->load('department');

        AuditLog::record($request->user()->id, 'role.created', Role::class, $role->id, $validated);

        return response()->json(['role' => $role], 201);
    }

    public function show(Request $request, int $roleId)
    {
        $role = Role::with('department')->findOrFail($roleId);

        return response()->json(['role' => $role->detailedArray()]);
    }

    public function updateModuleAccess(Request $request, int $roleId, int $moduleId, ModuleAccessService $moduleAccess)
    {
        $role = Role::findOrFail($roleId);

        $validated = $request->validate([
            'allowed' => ['required', 'boolean'],
        ]);

        DB::connection('tenant')->transaction(function () use ($role, $moduleId, $validated, $moduleAccess) {
            RoleModuleAccess::updateOrCreate(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                ['allowed' => $validated['allowed']]
            );

            // System modules carry their stations with them (Control Panel, …).
            $moduleAccess->syncRole($role, $moduleId, $validated['allowed']);
        });

        AuditLog::record($request->user()->id, 'role.module_access_updated', Role::class, $role->id, [
            'module_id' => $moduleId,
            'allowed' => $validated['allowed'],
        ]);

        // Role templates don't auto-propagate to assigned users (see resync).
        return response()->json([
            'role_id' => $role->id,
            'module_id' => $moduleId,
            'allowed' => $validated['allowed'],
            'note' => 'Applies to users assigned this role afterwards. Use resync to push to current users.',
        ]);
    }

    /**
     * Explicitly re-apply this role's current permission + module-access
     * template to every user currently holding it. Overwrites per-user tweaks.
     */
    public function resync(Request $request, int $roleId, UserRoleService $service)
    {
        $role = Role::findOrFail($roleId);

        $count = $service->resyncRoleUsers($role->id, TenantCache::currentTenantId());

        AuditLog::record($request->user()->id, 'role.resynced', Role::class, $role->id, [
            'users_resynced' => $count,
        ]);

        return response()->json([
            'role_id' => $role->id,
            'users_resynced' => $count,
        ]);
    }

    /** Blocked while any user currently holds the role — reassign them first. */
    public function destroy(Request $request, int $roleId)
    {
        $role = Role::findOrFail($roleId);

        if (User::where('role_id', $role->id)->exists()) {
            throw ValidationException::withMessages([
                'role' => ['This role still has users assigned to it. Reassign them first.'],
            ]);
        }

        $role->delete();

        AuditLog::record($request->user()->id, 'role.deleted', Role::class, $roleId, [
            'department_id' => $role->department_id,
            'designation_id' => $role->designation_id,
        ]);

        return response()->json(['message' => 'Role deleted.']);
    }
}
