<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['name', 'personal_email', 'gender', 'date_of_birth', 'status'];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function qualifications()
    {
        return $this->hasMany(StaffQualification::class);
    }
}
