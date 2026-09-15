<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * Who pays. `is_insurance = false` covers self-pay and the other non-insurance
 * arrangements, so billing has one path — see create_payer_tables.
 */
class Payer extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'name', 'display_name', 'payer_code', 'receiver_code',
        'malaffi_id', 'is_insurance', 'colour', 'sort_order', 'active',
    ];

    protected $casts = [
        'is_insurance' => 'boolean',
        'active' => 'boolean',
    ];

    /** The short label where the legal name won't fit, falling back to it. */
    public function label(): string
    {
        return $this->display_name ?: $this->name;
    }
}
