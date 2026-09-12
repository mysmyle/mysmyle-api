<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['name'];
}
