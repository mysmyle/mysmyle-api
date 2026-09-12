<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Permission;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Role;
use App\Models\Tenant\RoleHasPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RolePermissionController extends Controller
{
    public function updateForStation(Request $request, int $roleId, int $stationId)
    {
        $role = Role::findOrFail($roleId);

        $validated = $request->validate([
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer'],
        ]);

        $stationPermissionIds = Permission::where('station_id', $stationId)
            ->pluck('id');

        $invalidIds = collect($validated['permission_ids'])
            ->diff($stationPermissionIds);

        if ($invalidIds->isNotEmpty()) {
            return response()->json([
                'message' => 'One or more permission IDs do not belong to this station.',
            ], 422);
        }

        DB::connection('tenant')->transaction(function () use ($role, $stationPermissionIds, $validated) {
            RoleHasPermission::where('role_id', $role->id)
                ->whereIn('permission_id', $stationPermissionIds)
                ->delete();

            $rows = collect($validated['permission_ids'])->map(fn ($id) => [
                'role_id' => $role->id,
                'permission_id' => $id,
                'created_at' => now(),
            ])->all();

            if (! empty($rows)) {
                RoleHasPermission::insert($rows);
            }
        });

        AuditLog::record($request->user()->id, 'role.station_permissions_updated', Role::class, $role->id, [
            'station_id' => $stationId,
            'permission_ids' => $validated['permission_ids'],
        ]);

        // A role is a template. Editing it does NOT change users already assigned
        // to it (their permissions were snapshot-copied at assignment time) — so
        // nothing user-scoped needs cache invalidation here. To push these
        // changes to current holders, call POST /api/roles/{role}/resync.

        return response()->json([
            'role_id' => $role->id,
            'station_id' => $stationId,
            'permission_ids' => $validated['permission_ids'],
            'note' => 'Applies to users assigned this role afterwards. Use resync to push to current users.',
        ]);
    }
}
