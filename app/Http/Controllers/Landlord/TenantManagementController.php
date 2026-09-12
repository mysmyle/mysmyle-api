<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Landlord\AuditLog;
use App\Models\Landlord\PasswordSetupToken;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantUser;
use App\Models\Tenant\User;
use App\Services\PasswordSetupService;
use App\Services\TenantConnectionResolver;
use App\Services\TenantProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TenantManagementController extends Controller
{
    public function index(Request $request)
    {
        $pending = PasswordSetupToken::whereNull('used_at')->pluck('tenant_id')->flip();

        // Active user count comes from the landlord-side login row, which is kept
        // in lockstep with the tenant-side user's status — no per-tenant DB
        // connection needed for a platform-wide list.
        $activeUserCounts = TenantUser::where('status', 'active')
            ->selectRaw('tenant_id, count(*) as count')
            ->groupBy('tenant_id')
            ->pluck('count', 'tenant_id');

        $tenants = Tenant::all([
            'id', 'name', 'slug', 'db_name', 'status', 'provision_error', 'provisioned_at',
        ])->map(fn (Tenant $tenant) => $tenant->toArray() + [
            // the admin still has an unused set-password link
            'setup_pending' => $pending->has($tenant->id),
            'active_users_count' => (int) $activeUserCounts->get($tenant->id, 0),
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

    /**
     * Blocks every tenant user immediately — ResolveTenantConnection rejects
     * any request whose tenant isn't `active` on every call. Existing sessions
     * are also explicitly invalidated, so a later reactivate doesn't silently
     * hand old sessions access back without a fresh login.
     */
    public function suspend(Request $request, int $tenantId, TenantConnectionResolver $resolver)
    {
        $tenant = Tenant::findOrFail($tenantId);

        if ($tenant->status !== Tenant::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'status' => ['Only an active tenant can be suspended.'],
            ]);
        }

        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $resolver->bind($tenant);
        User::query()->update(['sessions_invalidated_at' => now()]);

        AuditLog::record($request->user()->id, 'tenant.suspended', Tenant::class, $tenant->id, [], $tenant->id);

        return response()->json(['tenant' => $tenant]);
    }

    public function reactivate(Request $request, int $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);

        if ($tenant->status !== Tenant::STATUS_SUSPENDED) {
            throw ValidationException::withMessages([
                'status' => ['Only a suspended tenant can be reactivated.'],
            ]);
        }

        $tenant->update(['status' => Tenant::STATUS_ACTIVE]);

        AuditLog::record($request->user()->id, 'tenant.reactivated', Tenant::class, $tenant->id, [], $tenant->id);

        return response()->json(['tenant' => $tenant]);
    }
}
