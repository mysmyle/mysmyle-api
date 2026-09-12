<?php

namespace App\Http\Middleware;

use App\Models\Landlord\LandlordAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveLandlordAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $adminId = session('landlord_admin_id');

        if (! $adminId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $admin = LandlordAdmin::find($adminId);

        if (! $admin || $admin->status !== 'active') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->setUserResolver(fn () => $admin);

        return $next($request);
    }
}
