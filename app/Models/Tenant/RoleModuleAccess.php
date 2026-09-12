<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class RoleModuleAccess extends Model
{
    protected $connection = 'tenant';

    protected $table = 'role_module_access';

    protected $fillable = ['role_id', 'module_id', 'allowed'];

    protected $casts = ['allowed' => 'boolean'];
}
