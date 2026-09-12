<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class UserModuleAccess extends Model
{
    protected $connection = 'tenant';

    protected $table = 'user_module_access';

    protected $fillable = ['user_id', 'module_id', 'allowed'];

    protected $casts = ['allowed' => 'boolean'];
}
