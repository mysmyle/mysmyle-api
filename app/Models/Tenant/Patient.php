<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The patient master. `chart` is the business key and the join key in 164
 * legacy tables; `full_name` is the canonical always-present name, with
 * first/middle/last filled in only where the parts are actually known.
 * See create_patients_table for the full reasoning.
 */
class Patient extends Model
{
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $fillable = [
        'nationality_id', 'title_id', 'gender_id', 'marital_status_id', 'religion_id',
        'document_presented_id', 'language_id', 'preferred_comm_language_id',
        'membership_type_id', 'status_id',
        'chart', 'full_name', 'first_name', 'middle_name', 'last_name', 'nickname',
        'date_of_birth', 'email', 'city', 'emirates',
    ];

    protected $casts = [
        'chart' => 'integer',
        'date_of_birth' => 'date',
    ];

    public function contactNumbers()
    {
        return $this->hasMany(PatientContactNumber::class);
    }

    public function payers()
    {
        return $this->hasMany(PatientPayer::class);
    }

    public function gender()
    {
        return $this->belongsTo(PatientLookup::class, 'gender_id');
    }

    public function nationality()
    {
        return $this->belongsTo(Country::class, 'nationality_id');
    }

    /** The patient's own number of one contact type, e.g. 'primary'. */
    public function contactNumberOfType(string $type): ?PatientContactNumber
    {
        return $this->contactNumbers
            ->first(fn (PatientContactNumber $number) => $number->contactType?->value === $type);
    }

    /** The cover the desk should see: primary if there is one, else the first active. */
    public function primaryPayer(): ?PatientPayer
    {
        $active = $this->payers->where('active', true);

        return $active->firstWhere('is_primary', true) ?? $active->first();
    }

    /**
     * The one search the front desk runs, from a single box: a chart number, a
     * phone number, or a name.
     *
     * The query decides which. Digits alone are never a name — nothing in
     * `full_name` is numeric — so a numeric term is matched against the chart
     * and the contact numbers only, and a term with any letter in it against
     * the name only. That keeps each branch on an index instead of running
     * three OR'd LIKEs over 10k patients for every keystroke.
     *
     * Name terms are AND'ed word by word, so "ali alkaabi" finds
     * "AADEL ALI SAEED ALKAABI" — the desk types the first and last name it
     * was given, which is almost never a contiguous substring of the full
     * string legacy stored.
     */
    public function scopeMatching(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $digits = preg_replace('/\D/', '', $term);

        // A phone number the caller reads out may carry its country code, a
        // leading zero, or neither. Matching on the tail covers all three
        // without storing a second normalised copy.
        if ($digits !== '' && $digits === preg_replace('/\s+/', '', $term)) {
            return $query->where(function (Builder $q) use ($digits) {
                $q->where('chart', $digits)
                    ->orWhere('chart', 'like', self::escapeLike($digits).'%')
                    ->orWhereHas('contactNumbers', fn (Builder $c) => $c
                        ->where('contact_number', 'like', '%'.self::escapeLike($digits)));
            });
        }

        foreach (preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where('full_name', 'like', '%'.self::escapeLike($word).'%');
        }

        return $query;
    }

    /**
     * Order an exact chart hit to the top. Typing a full chart number is the
     * desk's fastest path in, and a prefix match on a longer chart must never
     * sit above the row that IS that chart.
     */
    public function scopeOrderedForSearch(Builder $query, string $term): Builder
    {
        $digits = preg_replace('/\D/', '', trim($term));

        if ($digits !== '') {
            $query->orderByRaw('chart = ? desc', [$digits]);
        }

        return $query->orderBy('full_name')->orderBy('chart');
    }

    /** `_` and `%` typed into the box are literals, not wildcards. */
    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
