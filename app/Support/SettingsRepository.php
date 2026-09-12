<?php

namespace App\Support;

use App\Models\Landlord\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Cached read/write access to landlord `settings` rows. Values are plain arrays
 * (never objects — they round-trip through the cache serializer).
 */
class SettingsRepository
{
    private const CACHE_PREFIX = 'settings:';

    /**
     * The stored value merged over $defaults, so a partially-saved row still
     * resolves every key.
     */
    public function get(string $key, array $defaults = []): array
    {
        $stored = Cache::rememberForever(
            self::CACHE_PREFIX.$key,
            fn () => Setting::find($key)?->value ?? [],
        );

        return array_merge($defaults, $stored);
    }

    public function put(string $key, array $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    public function forget(string $key): void
    {
        Cache::forget(self::CACHE_PREFIX.$key);
    }
}
