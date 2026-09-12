<?php

namespace App\Models\Landlord;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class LandlordAdmin extends Authenticatable
{
    use Notifiable;

    protected $connection = 'landlord';

    protected $fillable = ['name', 'email', 'password', 'status'];

    protected $hidden = ['password'];

    protected $casts = ['password' => 'hashed'];
}
