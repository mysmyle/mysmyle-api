<?php

namespace App\Models\Tenant;

use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $connection = 'tenant';

    protected $fillable = ['staff_id', 'role_id', 'name', 'email', 'password', 'status', 'must_change_password', 'created_by', 'disabled_by', 'disabled_at'];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'hashed',
        'last_login_at' => 'datetime',
        'must_change_password' => 'boolean',
        'sessions_invalidated_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function disabledBy()
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    public function permissions()
    {
        return $this->hasMany(UserHasPermission::class);
    }

    public function moduleAccess()
    {
        return $this->hasMany(UserModuleAccess::class);
    }

    public function hasModuleAccess(int $moduleId): bool
    {
        $access = $this->moduleAccess()->where('module_id', $moduleId)->first();

        return $access ? $access->allowed : false;
    }

    public function detailedPermissions(): Collection
    {
        return PermissionCatalog::describe($this->permissions()->pluck('permission_id'));
    }

    /** Staff name for staff-linked accounts, own name for guest accounts. */
    public function displayName(): ?string
    {
        return $this->staff?->name ?? $this->name;
    }

    /** Compact shape for list views (no permission expansion). */
    public function listArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->displayName(),
            'email' => $this->email,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
            'staff' => $this->staff,
            'role' => $this->role, // designation is appended by the Role model
            'created_at' => $this->created_at,
            'created_by' => $this->createdBy?->displayName(),
            'disabled_at' => $this->disabled_at,
            'disabled_by' => $this->disabledBy?->displayName(),
        ];
    }

    /** Full shape for a single user, incl. resolved permissions + module access. */
    public function detailedArray(): array
    {
        return $this->listArray() + [
            'must_change_password' => (bool) $this->must_change_password,
            'permissions' => $this->detailedPermissions(),
            'module_access' => $this->moduleAccess()->get(['module_id', 'allowed']),
        ];
    }
}
