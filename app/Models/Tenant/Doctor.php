<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Doctor extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['staff_id', 'display_name', 'license_number', 'status'];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    /** The full legal name lives on the staff record; this model only holds the short form. */
    public function fullName(): ?string
    {
        return $this->staff?->name;
    }
}
