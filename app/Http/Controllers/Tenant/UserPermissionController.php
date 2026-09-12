<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Permission;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\User;
use App\Models\Tenant\UserHasPermission;
use App\Support\TenantCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserPermissionController extends Controller
{
    public function updateForStation(Request $request, int $userId, int $stationId)
    {
        $user = User::findOrFail($userId);

        $validated = $request->validate([
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer'],
        ]);

        $stationPermissionIds = Permission::where('station_id', $stationId)->pluck('id');

        $invalidIds = collect($validated['permission_ids'])->diff($stationPermissionIds);

        if ($invalidIds->isNotEmpty()) {
            return response()->json([
                'message' => 'One or more permission IDs do not belong to this station.',
            ], 422);
        }

        DB::connection('tenant')->transaction(function () use ($user, $stationPermissionIds, $validated) {
            UserHasPermission::where('user_id', $user->id)
                ->whereIn('permission_id', $stationPermissionIds)
                ->delete();

            $rows = collect($validated['permission_ids'])->map(fn ($id) => [
                'user_id' => $user->id,
                'permission_id' => $id,
                'created_at' => now(),
            ])->all();

            if (! empty($rows)) {
                UserHasPermission::insert($rows);
            }
        });

        TenantCache::forgetUserPermissions(TenantCache::currentTenantId(), $user->id);

        AuditLog::record($request->user()->id, 'user.station_permissions_updated', User::class, $user->id, [
            'station_id' => $stationId,
            'permission_ids' => $validated['permission_ids'],
        ]);

        return response()->json([
            'user_id' => $user->id,
            'station_id' => $stationId,
            'permission_ids' => $validated['permission_ids'],
        ]);
    }
}
