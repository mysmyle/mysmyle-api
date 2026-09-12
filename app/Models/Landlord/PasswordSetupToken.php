<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

/**
 * A single-use, time-limited token that lets a newly provisioned tenant admin
 * choose their first password. Lives landlord-side because the link is followed
 * before any tenant session exists — the row carries the tenant_id to bind.
 * The `token` column stores sha256(hex) of the value in the emailed link.
 */
class PasswordSetupToken extends Model
{
    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'email', 'token', 'expires_at', 'used_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];
}
