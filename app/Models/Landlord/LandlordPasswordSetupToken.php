<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

/**
 * A single-use, time-limited token that lets an invited landlord admin choose
 * their first password. The `token` column stores sha256(hex) of the value
 * in the emailed link — mirrors PasswordSetupToken on the tenant side.
 */
class LandlordPasswordSetupToken extends Model
{
    protected $connection = 'landlord';

    protected $fillable = ['landlord_admin_id', 'token', 'expires_at', 'used_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function admin()
    {
        return $this->belongsTo(LandlordAdmin::class, 'landlord_admin_id');
    }
}
