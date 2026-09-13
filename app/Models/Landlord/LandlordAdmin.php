<?php

namespace App\Models\Landlord;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class LandlordAdmin extends Authenticatable
{
    use Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_SUPPORT = 'support';

    protected $connection = 'landlord';

    protected $fillable = ['name', 'email', 'password', 'status', 'role'];

    protected $hidden = ['password'];

    protected $casts = ['password' => 'hashed'];

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }
}
