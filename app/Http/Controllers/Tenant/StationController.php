<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Module;
use Illuminate\Http\Request;

class StationController extends Controller
{
    public function index(Request $request, Module $module)
    {
        return response()->json([
            'module' => $module->only(['id', 'name', 'abbreviation']),
            'stations' => $module->stations()->with('permissions')->get(),
        ]);
    }
}
