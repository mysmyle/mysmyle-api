<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class StaffQualification extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'staff_id', 'title', 'issuing_org', 'credential_no', 'issued_on', 'expires_on', 'status',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'expires_on' => 'date',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}
