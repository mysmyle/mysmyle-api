<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A patient's cover — one row per cover, so primary and secondary can coexist
 * and a renewal is a new row rather than an overwrite.
 */
class PatientPayer extends Model
{
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $fillable = [
        'patient_id', 'payer_id', 'payer_policy_id', 'payer_package_id', 'payer_third_party_id',
        'insurance_number', 'reimbursement_card_name', 'issue_date', 'expiry_date',
        'expiry_unknown', 'coverage_percent', 'is_primary', 'active', 'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'expiry_unknown' => 'boolean',
        'is_primary' => 'boolean',
        'active' => 'boolean',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function payer()
    {
        return $this->belongsTo(Payer::class);
    }

    /**
     * Expired means we KNOW it has lapsed. A cover with no expiry date, or one
     * flagged `expiry_unknown`, is not expired — it is simply unverified, and
     * showing it as expired on the search board would send the desk chasing a
     * card that is probably fine.
     */
    public function isExpired(): bool
    {
        if ($this->expiry_unknown || ! $this->expiry_date) {
            return false;
        }

        return $this->expiry_date->isPast();
    }
}
