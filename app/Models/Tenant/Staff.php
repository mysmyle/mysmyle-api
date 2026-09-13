<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['name', 'personal_email', 'gender', 'date_of_birth', 'status', 'created_by', 'disabled_by', 'disabled_at'];

    protected $casts = [
        'date_of_birth' => 'date',
        'disabled_at' => 'datetime',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function qualifications()
    {
        return $this->hasMany(StaffQualification::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function disabledBy()
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }
}
