<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class UserHasPermission extends Model
{
    protected $connection = 'tenant';

    public $timestamps = false;

    protected $fillable = ['user_id', 'permission_id'];
}
