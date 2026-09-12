<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class Designation extends Model
{
    protected $connection = 'landlord';

    protected $fillable = ['name', 'level'];

    protected $casts = ['level' => 'integer'];

    /**
     * All designations as plain `id => [id, name, level]` rows. Tenant
     * `roles.designation_id` points here (cross-database, no FK), so this is the
     * lookup used to attach a designation wherever a role is serialized.
     *
     * Cached as plain arrays (not models) so it survives the cache round-trip.
     */
    public static function catalog(): Collection
    {
        $rows = Cache::remember(
            'designations:catalog',
            now()->addHours(24),
            fn () => static::orderBy('level')
                ->get(['id', 'name', 'level'])
                ->keyBy('id')
                ->toArray(),
        );

        return collect($rows);
    }

    public static function fromCatalog(int|string|null $id): ?array
    {
        return $id === null ? null : (static::catalog()->get((int) $id) ?: null);
    }

    public static function flushCatalog(): void
    {
        Cache::forget('designations:catalog');
    }
}
