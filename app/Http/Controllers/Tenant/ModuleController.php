<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Module;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    /**
     * List modules with the current user's access flag.
     *
     * ?kind=clinical (default) — functional areas shown in the product nav.
     * ?kind=system             — app capabilities (e.g. Control Panel).
     * ?kind=all                — both, for the permission editors.
     */
    public function index(Request $request)
    {
        $kind = $request->query('kind', Module::KIND_CLINICAL);

        $query = Module::where('is_visible', true);

        if ($kind !== 'all') {
            $query->where('kind', $kind);
        }

        $modules = $query->get(['id', 'name', 'abbreviation', 'kind']);

        $user = $request->user();

        // One query for all of the user's module grants, not one per module.
        $access = $user
            ? $user->moduleAccess()->pluck('allowed', 'module_id')
            : collect();

        $modules = $modules->map(function ($module) use ($access) {
            $module->allowed = (bool) $access->get($module->id, false);

            return $module;
        });

        return response()->json(['modules' => $modules]);
    }
}
