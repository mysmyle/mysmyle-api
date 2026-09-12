<?php

namespace App\Providers;

use App\Services\TenantConnectionResolver;
use App\Services\TenantDatabaseManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons so a test can swap the DB manager for a fake and have
        // every resolver/service pick it up.
        $this->app->singleton(TenantDatabaseManager::class);
        $this->app->singleton(TenantConnectionResolver::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/landlord'));

        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        // Credential-stuffing guard for both login endpoints. Per email+IP so a
        // shared NAT isn't punished for one bad actor, plus a looser per-IP cap
        // to slow someone spraying many emails from one host.
        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(20)->by('ip:'.$request->ip()),
            ];
        });

        // Baseline cap for the rest of the API — generous enough to never bite
        // normal SPA traffic, low enough to stop a flood.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip()
        ));
    }
}
