<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\User;
use App\Services\UserRoleService;
use App\Support\TenantCache;
use Illuminate\Http\Request;

class UserRoleController extends Controller
{
    public function update(Request $request, int $userId, UserRoleService $service)
    {
        $user = User::findOrFail($userId);

        $validated = $request->validate([
            'role_id' => ['nullable', 'integer', 'exists:tenant.roles,id'],
        ]);

        $service->assignRole($user, $validated['role_id'], TenantCache::currentTenantId());

        AuditLog::record($request->user()->id, 'user.role_updated', User::class, $user->id, [
            'role_id' => $validated['role_id'],
        ]);

        $user = User::with(['staff', 'role.department'])->findOrFail($userId);

        return response()->json(['user' => $user->detailedArray()]);
    }
}
