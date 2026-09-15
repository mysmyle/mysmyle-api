<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * One reference table for every enumerable value in the patient module, typed
 * by `type` — see the create_patient_lookups_table migration for the list.
 */
class PatientLookup extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['type', 'value', 'name', 'sort_order', 'active'];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
