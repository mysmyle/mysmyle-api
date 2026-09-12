<?php

namespace App\Http\Middleware;

use App\Models\Landlord\Permission;
use App\Models\Tenant\UserHasPermission;
use App\Support\TenantCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Usage: 'permission:RAP.PATIENTS,add' — station code + action.
     */
    public function handle(Request $request, Closure $next, string $stationCode, string $action): Response
    {
        $user = $request->user();
        $tenantId = TenantCache::currentTenantId();

        if (! $user || ! $tenantId) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $this->userHasPermission($tenantId, $user->id, $stationCode, $action)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }

    protected function userHasPermission(int $tenantId, int $userId, string $stationCode, string $action): bool
    {
        $permissionCodes = Cache::remember(
            TenantCache::userPermissionsKey($tenantId, $userId),
            now()->addHours(TenantCache::PERMISSIONS_TTL_HOURS),
            fn () => $this->buildPermissionCodes($userId),
        );

        return in_array("{$stationCode}:{$action}", $permissionCodes, true);
    }

    /**
     * "station_code:action" for every permission granted to the user. The
     * permission catalog lives in the landlord DB, so resolve it in one query
     * rather than per id.
     *
     * Returns a plain array — cached values must survive an igbinary/serialize
     * round-trip through Redis, which turns cached objects into
     * __PHP_Incomplete_Class.
     *
     * @return list<string>
     */
    protected function buildPermissionCodes(int $userId): array
    {
        $permissionIds = UserHasPermission::where('user_id', $userId)->pluck('permission_id');

        return Permission::with('station:id,code')
            ->whereIn('id', $permissionIds)
            ->get()
            ->map(fn ($permission) => $permission->station
                ? "{$permission->station->code}:{$permission->action}"
                : null)
            ->filter()
            ->values()
            ->all();
    }
}
