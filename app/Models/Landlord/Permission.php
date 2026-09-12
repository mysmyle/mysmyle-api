<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $connection = 'landlord';

    protected $fillable = ['station_id', 'action'];

    public function station()
    {
        return $this->belongsTo(Station::class);
    }
}
