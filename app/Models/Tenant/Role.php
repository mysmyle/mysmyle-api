<?php

namespace App\Models\Tenant;

use App\Models\Landlord\Designation;
use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Role extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['department_id', 'designation_id'];

    protected $casts = [
        'department_id' => 'integer',
        'designation_id' => 'integer',
    ];

    /**
     * The designation lives in the landlord DB (no cross-connection relation),
     * so it is resolved from the cached catalog and always serialized with the
     * role.
     */
    protected $appends = ['designation'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function permissions()
    {
        return $this->hasMany(RoleHasPermission::class);
    }

    public function moduleAccess()
    {
        return $this->hasMany(RoleModuleAccess::class);
    }

    protected function designation(): Attribute
    {
        return Attribute::get(fn () => Designation::fromCatalog($this->designation_id))
            ->shouldCache();
    }

    // designation() returns a plain array (from the cached catalog), not a model.

    public function detailedPermissions(): Collection
    {
        return PermissionCatalog::describe($this->permissions()->pluck('permission_id'));
    }

    public function detailedArray(): array
    {
        return [
            'id' => $this->id,
            'department' => $this->department,
            'designation' => $this->designation,
            'permissions' => $this->detailedPermissions(),
            'module_access' => $this->moduleAccess()->get(['module_id', 'allowed']),
        ];
    }

    public function hasModuleAccess(int $moduleId): bool
    {
        $access = $this->moduleAccess()->where('module_id', $moduleId)->first();

        return $access ? $access->allowed : false; // defaults to Denied if no row exists
    }
}
