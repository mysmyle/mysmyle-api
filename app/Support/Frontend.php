<?php

namespace App\Support;

/**
 * Builds absolute URLs into the React SPA (config: app.frontend_url). Use this
 * for every link that goes into an email or an external redirect — never
 * url()/route(), which point at the API host.
 */
class Frontend
{
    public static function url(string $path = ''): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }
}
