<?php

namespace App\Http\Middleware;

use App\Models\Tenant\User;
use App\Services\TenantConnectionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantConnection
{
    public function __construct(protected TenantConnectionResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = session('tenant_id');

        if (! $tenantId) {
            return response()->json(['message' => 'No tenant context.'], 403);
        }

        $tenant = $this->resolver->resolveAndBind((int) $tenantId);

        if (! $tenant) {
            return response()->json(['message' => 'Tenant not available.'], 403);
        }

        app()->instance('currentTenant', $tenant);

        $userId = session('auth_user_id');

        if (! $userId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = User::find($userId);

        if (! $user || $user->status !== 'active') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
