<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per phone number belonging to the patient, keyed by
 * (patient_id, contact_type_id). `contact_number` is digits only, with the
 * country code held separately as `country_id`.
 */
class PatientContactNumber extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'patient_id', 'contact_type_id', 'country_id', 'contact_number', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function contactType()
    {
        return $this->belongsTo(PatientLookup::class, 'contact_type_id');
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
