# MySmyle API

Backend for **MySmyle**, a multi-tenant ERP for dental clinics. Laravel 13 / PHP 8.3,
MariaDB, Redis, Sanctum (SPA cookie auth). The React frontend lives in `../mysmyle-web`.

## Architecture

- **Database-per-tenant.** A central `landlord` database holds the tenant registry,
  platform admins, and the global RBAC catalog (modules, stations, permissions,
  designations). Each clinic gets its own database and a scoped MySQL user,
  provisioned by a queued job.
- **Two auth realms** sharing the session: tenant users (`/api/*`) and platform
  super-admins (`/api/landlord/*`).
- **RBAC:** Module → Station → Permission. A role (department × designation) carries a
  permission template that is snapshot-copied onto users when assigned.

See [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) for local setup, the DB layout, and the
tenant CLI commands.

## Quick start

```sh
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --database=landlord # (see .env DB_DRIVER)
php artisan db:seed

php artisan serve --port=8000
php artisan queue:work          # required — tenant provisioning runs on the queue
```

## Tests & style

```sh
php artisan test
./vendor/bin/pint
```
