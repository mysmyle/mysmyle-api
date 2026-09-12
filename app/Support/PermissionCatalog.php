<?php

namespace App\Support;

use App\Models\Landlord\Permission;
use Illuminate\Support\Collection;

/**
 * Turns a set of landlord permission ids into the nested
 * permission / station / module shape the API returns.
 *
 * Both User and Role granted-permission lists are built from this, so the
 * payload is identical wherever permissions appear.
 */
class PermissionCatalog
{
    public static function describe(iterable $permissionIds): Collection
    {
        $ids = collect($permissionIds)->all();

        if (empty($ids)) {
            return collect();
        }

        return Permission::with('station.module')
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (Permission $permission) => [
                'id' => $permission->id,
                'action' => $permission->action,
                'station' => $permission->station ? [
                    'id' => $permission->station->id,
                    'name' => $permission->station->name,
                    'code' => $permission->station->code,
                ] : null,
                'module' => $permission->station?->module ? [
                    'id' => $permission->station->module->id,
                    'name' => $permission->station->module->name,
                    'abbreviation' => $permission->station->module->abbreviation,
                ] : null,
            ])
            ->filter(fn ($row) => $row['station'] !== null)
            ->values();
    }
}
