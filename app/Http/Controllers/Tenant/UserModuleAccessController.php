<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\User;
use App\Models\Tenant\UserModuleAccess;
use App\Services\ModuleAccessService;
use App\Support\TenantCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserModuleAccessController extends Controller
{
    public function update(Request $request, int $userId, int $moduleId, ModuleAccessService $moduleAccess)
    {
        $user = User::findOrFail($userId);

        $validated = $request->validate([
            'allowed' => ['required', 'boolean'],
        ]);

        DB::connection('tenant')->transaction(function () use ($user, $moduleId, $validated, $moduleAccess) {
            UserModuleAccess::updateOrCreate(
                ['user_id' => $user->id, 'module_id' => $moduleId],
                ['allowed' => $validated['allowed']]
            );

            // System modules carry their stations with them (Control Panel, …).
            $moduleAccess->syncUser($user, $moduleId, $validated['allowed'], TenantCache::currentTenantId());
        });

        return response()->json([
            'user_id' => $user->id,
            'module_id' => $moduleId,
            'allowed' => $validated['allowed'],
        ]);
    }
}
