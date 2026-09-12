<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a tenant user carrying a system-generated temporary password from
 * everything except reading their profile, logging out, and setting a new
 * password. The SPA also gates this client-side; this is the enforcement.
 */
class EnsurePasswordIsSet
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            return response()->json([
                'message' => 'You must set a new password before continuing.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
