<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    protected $connection = 'landlord';

    protected $fillable = ['module_id', 'code', 'name'];

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function permissions()
    {
        return $this->hasMany(Permission::class);
    }
}
