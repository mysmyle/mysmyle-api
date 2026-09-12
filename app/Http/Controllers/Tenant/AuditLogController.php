<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = AuditLog::with('user:id,name,staff_id,email')
            ->with('user.staff:id,name')
            ->latest('id')
            ->paginate(50);

        $logs->getCollection()->transform(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'meta' => $log->meta,
            'created_at' => $log->created_at,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->displayName(),
                'email' => $log->user->email,
            ] : null,
        ]);

        return response()->json($logs);
    }
}
