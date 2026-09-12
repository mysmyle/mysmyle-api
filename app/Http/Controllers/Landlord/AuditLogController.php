<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Landlord\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = AuditLog::with('admin:id,name,email', 'tenant:id,name,slug')
            ->latest('id')
            ->paginate(50);

        $logs->getCollection()->transform(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'meta' => $log->meta,
            'created_at' => $log->created_at,
            'admin' => $log->admin ? ['id' => $log->admin->id, 'name' => $log->admin->name, 'email' => $log->admin->email] : null,
            'tenant' => $log->tenant ? ['id' => $log->tenant->id, 'name' => $log->tenant->name, 'slug' => $log->tenant->slug] : null,
        ]);

        return response()->json($logs);
    }
}
