<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'num_code', 'alpha_2_code', 'alpha_3_code', 'en_short_name',
        'nationality', 'shafafiya_nationality_code', 'phone_code', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];
}
