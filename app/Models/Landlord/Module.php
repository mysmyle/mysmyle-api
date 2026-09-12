<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Module extends Model
{
    public const KIND_CLINICAL = 'clinical';

    public const KIND_SYSTEM = 'system';

    /** Abbreviation of the Control Panel system module. */
    public const CONTROL_PANEL = 'CP';

    protected $connection = 'landlord';

    protected $fillable = ['name', 'abbreviation', 'kind', 'is_visible'];

    protected $casts = [
        'is_visible' => 'boolean',
    ];

    public function stations()
    {
        return $this->hasMany(Station::class);
    }

    /** Resolve a module id from its abbreviation (cached). */
    public static function idForAbbreviation(string $abbreviation): ?int
    {
        return Cache::remember(
            "module_id:{$abbreviation}",
            now()->addHours(24),
            fn () => static::where('abbreviation', $abbreviation)->value('id')
        );
    }

    public static function controlPanelId(): ?int
    {
        return static::idForAbbreviation(self::CONTROL_PANEL);
    }

    public function scopeClinical(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_CLINICAL);
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_SYSTEM);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }
}
