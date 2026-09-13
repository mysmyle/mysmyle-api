<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the super_admin-only slice of the landlord API (tenant lifecycle,
 * admin management, platform settings) behind the tier set on the
 * authenticated LandlordAdmin. Sits behind 'landlord.admin', which already
 * resolved $request->user().
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSuperAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
