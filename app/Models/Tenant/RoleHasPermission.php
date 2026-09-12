<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class RoleHasPermission extends Model
{
    protected $connection = 'tenant';

    public $timestamps = false;

    protected $fillable = ['role_id', 'permission_id'];
}
