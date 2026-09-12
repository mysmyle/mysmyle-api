<?php

namespace App\Http\Middleware;

use App\Models\Landlord\Module;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleAccess
{
    /**
     * Usage: 'module.access:5' (module id) or 'module.access:CP' (abbreviation).
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();
        $moduleId = $this->resolveModuleId($module);

        if (! $user || ! $moduleId || ! $user->hasModuleAccess($moduleId)) {
            return response()->json(['message' => 'Access Denied.'], 403);
        }

        return $next($request);
    }

    protected function resolveModuleId(string $module): ?int
    {
        if (is_numeric($module)) {
            return (int) $module;
        }

        return Module::idForAbbreviation($module);
    }
}
