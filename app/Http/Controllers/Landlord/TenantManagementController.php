<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Landlord\AuditLog;
use App\Models\Landlord\PasswordSetupToken;
use App\Models\Landlord\Tenant;
use App\Services\PasswordSetupService;
use App\Services\TenantProvisioningService;
use Illuminate\Http\Request;

class TenantManagementController extends Controller
{
    public function index(Request $request)
    {
        $pending = PasswordSetupToken::whereNull('used_at')->pluck('tenant_id')->flip();

        $tenants = Tenant::all([
            'id', 'name', 'slug', 'db_name', 'status', 'provision_error', 'provisioned_at',
        ])->map(fn (Tenant $tenant) => $tenant->toArray() + [
            // the admin still has an unused set-password link
            'setup_pending' => $pending->has($tenant->id),
        ]);

        return response()->json(['tenants' => $tenants]);
    }

    public function store(Request $request, TenantProvisioningService $service)
    {
        // Slug + database name are derived from the clinic name by the service.
        $validated = $request->validate([
            'tenant_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string'],
            'admin_email' => ['required', 'email'],
        ]);

        $tenant = $service->startProvisioning(
            $validated['tenant_name'],
            $validated['admin_name'],
            $validated['admin_email'],
        );

        AuditLog::record($request->user()->id, 'tenant.created', Tenant::class, $tenant->id, [
            'name' => $validated['tenant_name'],
            'admin_email' => $validated['admin_email'],
        ], $tenant->id);

        // 202: the tenant record exists; the database, admin account and
        // set-password email are built by a queued job.
        return response()->json(['tenant' => $tenant], 202);
    }

    /**
     * Re-issue and re-email the admin's set-password link (expired or lost).
     */
    public function resendSetupLink(Request $request, int $tenantId, PasswordSetupService $service)
    {
        $tenant = Tenant::findOrFail($tenantId);

        $service->reissueForTenant($tenant);

        AuditLog::record($request->user()->id, 'tenant.setup_link_resent', Tenant::class, $tenant->id, [], $tenant->id);

        return response()->json(['message' => 'A new set-password link was emailed to the admin.']);
    }
}
