<?php

use App\Http\Controllers\Landlord\AuditLogController as LandlordAuditLogController;
use App\Http\Controllers\Landlord\ImpersonationController as LandlordImpersonationController;
use App\Http\Controllers\Landlord\LandlordAdminController;
use App\Http\Controllers\Landlord\LandlordAuthController;
use App\Http\Controllers\Landlord\LandlordSetPasswordController;
use App\Http\Controllers\Landlord\PasswordController as LandlordPasswordController;
use App\Http\Controllers\Landlord\SettingsController;
use App\Http\Controllers\Landlord\TenantManagementController;
use App\Http\Controllers\SetPasswordController;
use App\Http\Controllers\Tenant\AuditLogController;
use App\Http\Controllers\Tenant\AuthController;
use App\Http\Controllers\Tenant\DepartmentController;
use App\Http\Controllers\Tenant\DesignationController;
use App\Http\Controllers\Tenant\ImpersonationController;
use App\Http\Controllers\Tenant\ModuleController;
use App\Http\Controllers\Tenant\PasswordController;
use App\Http\Controllers\Tenant\RoleController;
use App\Http\Controllers\Tenant\RolePermissionController;
use App\Http\Controllers\Tenant\StaffController;
use App\Http\Controllers\Tenant\StaffQualificationController;
use App\Http\Controllers\Tenant\StationController;
use App\Http\Controllers\Tenant\UserController;
use App\Http\Controllers\Tenant\UserModuleAccessController;
use App\Http\Controllers\Tenant\UserPermissionController;
use App\Http\Controllers\Tenant\UserRoleController;
use App\Models\Landlord\Module;
use Illuminate\Support\Facades\Route;

Route::post('/landlord/login', [LandlordAuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['landlord.admin'])->group(function () {
    Route::post('/landlord/logout', [LandlordAuthController::class, 'logout']);
    Route::get('/landlord/me', [LandlordAuthController::class, 'me']);
    Route::post('/landlord/password', [LandlordPasswordController::class, 'update']);

    // Available to every active admin, regardless of tier — read access plus
    // the two support-safe actions (resend a lost link, impersonate).
    Route::get('/landlord/tenants', [TenantManagementController::class, 'index']);
    Route::get('/landlord/tenants/{tenantId}', [TenantManagementController::class, 'show']);
    Route::post('/landlord/tenants/{tenantId}/resend-setup-link', [TenantManagementController::class, 'resendSetupLink']);
    Route::get('/landlord/audit-logs', [LandlordAuditLogController::class, 'index']);
    Route::get('/landlord/tenants/{tenantId}/users', [LandlordImpersonationController::class, 'index']);
    Route::post('/landlord/tenants/{tenantId}/impersonate/{userId}', [LandlordImpersonationController::class, 'start']);

    // super_admin only — tenant lifecycle, platform settings, admin management.
    Route::middleware(['landlord.super'])->group(function () {
        Route::post('/landlord/tenants', [TenantManagementController::class, 'store']);
        Route::post('/landlord/tenants/{tenantId}/suspend', [TenantManagementController::class, 'suspend']);
        Route::post('/landlord/tenants/{tenantId}/reactivate', [TenantManagementController::class, 'reactivate']);
        Route::get('/landlord/settings/password-policy', [SettingsController::class, 'passwordPolicy']);
        Route::put('/landlord/settings/password-policy', [SettingsController::class, 'updatePasswordPolicy']);
        Route::get('/landlord/admins', [LandlordAdminController::class, 'index']);
        Route::post('/landlord/admins', [LandlordAdminController::class, 'store']);
        Route::put('/landlord/admins/{adminId}', [LandlordAdminController::class, 'update']);
        Route::post('/landlord/admins/{adminId}/resend-setup-link', [LandlordAdminController::class, 'resendSetupLink']);
        Route::post('/landlord/admins/{adminId}/reset-password', [LandlordAdminController::class, 'resetPassword']);
    });
});

// Public — the emailed set-password link for an invited landlord admin.
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/landlord/set-password/{token}', [LandlordSetPasswordController::class, 'show']);
    Route::post('/landlord/set-password', [LandlordSetPasswordController::class, 'store']);
});

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Public — the emailed set-password link for a newly provisioned tenant admin.
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/set-password/{token}', [SetPasswordController::class, 'show']);
    Route::post('/set-password', [SetPasswordController::class, 'store']);
});

Route::middleware(['tenant'])->group(function () {
    // Available to any authenticated tenant user, even one still on a temporary
    // password (so they can read their profile, log out, and set a new one).
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/password', [PasswordController::class, 'update']);
    Route::get('/password-policy', [PasswordController::class, 'policy']);
    Route::post('/impersonate/stop', [ImpersonationController::class, 'stop']);

    // Everything else is blocked while must_change_password is set.
    Route::middleware('password.set')->group(function () {
        Route::get('/modules', [ModuleController::class, 'index']);

        // Control Panel — administration surface. Requires the CP system module,
        // then a per-section permission (CP.STAFF / CP.USERS / CP.ROLES / CP.CATALOG,
        // each view/add/edit). Seeded by StationSeeder::seedControlPanelStations.
        Route::middleware('module.access:'.Module::CONTROL_PANEL)->group(function () {
            // Departments & Roles
            Route::middleware('permission:CP.ROLES,view')->group(function () {
                Route::get('/departments', [DepartmentController::class, 'index']);
                Route::get('/roles', [RoleController::class, 'index']);
                Route::get('/roles/{roleId}', [RoleController::class, 'show']);
            });
            Route::middleware('permission:CP.ROLES,add')->group(function () {
                Route::post('/departments', [DepartmentController::class, 'store']);
                Route::post('/roles', [RoleController::class, 'store']);
            });
            Route::middleware('permission:CP.ROLES,edit')->group(function () {
                Route::put('/departments/{departmentId}', [DepartmentController::class, 'update']);
                Route::put('/roles/{roleId}/modules/{moduleId}/access', [RoleController::class, 'updateModuleAccess']);
                Route::post('/roles/{roleId}/resync', [RoleController::class, 'resync']);
                Route::put('/roles/{role}/stations/{stationId}/permissions', [RolePermissionController::class, 'updateForStation']);
                Route::delete('/departments/{departmentId}', [DepartmentController::class, 'destroy']);
                Route::delete('/roles/{roleId}', [RoleController::class, 'destroy']);
            });

            // Modules & Designations — read-only catalog
            Route::middleware('permission:CP.CATALOG,view')->group(function () {
                Route::get('/designations', [DesignationController::class, 'index']);
                Route::get('/modules/{module}/stations', [StationController::class, 'index']);
            });

            // Audit Log — read-only history of Control Panel actions
            Route::middleware('permission:CP.AUDIT,view')->group(function () {
                Route::get('/audit-logs', [AuditLogController::class, 'index']);
            });

            // Staff Members
            Route::middleware('permission:CP.STAFF,view')->group(function () {
                Route::get('/staff', [StaffController::class, 'index']);
                Route::get('/staff/{staffId}', [StaffController::class, 'show']);
            });
            Route::post('/staff', [StaffController::class, 'store'])->middleware('permission:CP.STAFF,add');
            Route::put('/staff/{staffId}', [StaffController::class, 'update'])->middleware('permission:CP.STAFF,edit');
            Route::delete('/staff/{staffId}', [StaffController::class, 'destroy'])->middleware('permission:CP.STAFF,edit');

            // User Accounts (incl. their per-user role / module / station access)
            Route::middleware('permission:CP.USERS,view')->group(function () {
                Route::get('/users', [UserController::class, 'index']);
                Route::get('/users/{userId}', [UserController::class, 'show']);
            });
            Route::post('/users', [UserController::class, 'store'])->middleware('permission:CP.USERS,add');
            Route::middleware('permission:CP.USERS,edit')->group(function () {
                Route::put('/users/{userId}', [UserController::class, 'update']);
                Route::post('/users/{userId}/password/reset', [UserController::class, 'resetPassword']);
                Route::post('/users/{userId}/force-logout', [UserController::class, 'forceLogout']);
                Route::put('/users/{userId}/role', [UserRoleController::class, 'update']);
                Route::put('/users/{userId}/modules/{moduleId}/access', [UserModuleAccessController::class, 'update']);
                Route::put('/users/{userId}/stations/{stationId}/permissions', [UserPermissionController::class, 'updateForStation']);
            });
        });

        // Staff Qualification & Education — clinical module, independent of Control
        // Panel's CP.STAFF gate. staffOptions exposes only id+name so a holder of
        // this module doesn't need CP.STAFF access to record a qualification.
        Route::middleware('module.access:SQE')->group(function () {
            Route::middleware('permission:SQE.QUALIFICATIONS,view')->group(function () {
                Route::get('/staff-qualifications', [StaffQualificationController::class, 'index']);
                Route::get('/staff-qualifications/staff-options', [StaffQualificationController::class, 'staffOptions']);
                Route::get('/staff-qualifications/{qualificationId}', [StaffQualificationController::class, 'show']);
            });
            Route::post('/staff-qualifications', [StaffQualificationController::class, 'store'])
                ->middleware('permission:SQE.QUALIFICATIONS,add');
            Route::middleware('permission:SQE.QUALIFICATIONS,edit')->group(function () {
                Route::put('/staff-qualifications/{qualificationId}', [StaffQualificationController::class, 'update']);
                Route::delete('/staff-qualifications/{qualificationId}', [StaffQualificationController::class, 'destroy']);
            });
        });
    });
});
