# Development notes

## Running the app locally

```sh
php artisan serve --host=localhost --port=8000
php artisan queue:work          # REQUIRED — tenant provisioning runs on the queue
npm run dev                     # (mysmyle-web) Vite dev server on :5173
```

`QUEUE_CONNECTION=database`; the `jobs` table lives in the landlord DB. Without a
running worker, new tenants stay stuck at `status = provisioning`. For a
throwaway setup you can set `QUEUE_CONNECTION=sync` to run jobs inline.

**`php artisan serve` on Windows is single-threaded** — pages that fire several
API calls at once can hang if stale server processes are lingering. `artisan
serve` spawns a child `php -S …` process; killing the `artisan serve` wrapper
leaves the child bound to the port. If requests start hanging, kill *every* PHP
dev process and restart one:

```powershell
Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
  Where-Object { $_.CommandLine -like '*server.php*' -or $_.CommandLine -like '*artisan serve*' } |
  ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
```

Or serve the API through Laragon's Apache vhost (`mysmyle-erp-api.test`) instead —
it handles concurrency properly. That changes the origin, so update
`VITE_API_URL`, and note the SPA cookie setup assumes API and frontend are
same-site (both `localhost`).

## Tests

```sh
php artisan test
```

Feature tests run against a **real MariaDB database** (provisioning does
engine-specific DDL). Create it once:

```sql
CREATE DATABASE mysmyle_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

`phpunit.xml` points both the `landlord` and `tenant` connections at that one
schema; `tests/TestCase.php` runs `migrate:fresh` once then truncates between
tests. The physical side of tenancy (`App\Services\TenantDatabaseManager` —
`CREATE DATABASE` / `CREATE USER` / connection binding) is swapped for
`Tests\Support\FakeTenantDatabaseManager`, so tests never create real per-tenant
databases. Data builders live in `Tests\Concerns\BuildsTenantData`.

## Databases

Two connections (see `config/database.php`):

- `landlord` (`mysmyle_landlord`) — central: tenants registry, `tenant_users`,
  `landlord_admins`, and the global RBAC catalog (`modules`, `stations`,
  `permissions`, `designations`). Also `sessions`, `cache`, `jobs`.
- `tenant` — one physical database per clinic, each with its own scoped MySQL
  user `mysmyle_t{id}`. Credentials are injected at runtime by
  `TenantConnectionResolver` and are never cached.

Landlord migrations run with plain `php artisan migrate` (auto-loaded from
`database/migrations/landlord` by `AppServiceProvider`). Tenant migrations live
in `database/migrations/tenant` and run per-tenant during provisioning.

```sh
php artisan make:migration create_x_table --path=database/migrations/tenant
```

## Tenant provisioning

Tenants are created through the landlord API (`POST /api/landlord/tenants` —
clinic name + admin name + admin **email**, no password), which reserves the
record and dispatches `App\Jobs\ProvisionTenant`. The job creates the database +
scoped MySQL user, runs tenant migrations, seeds the admin (staff + user with
**no password** + "Administration" department + roles, with Control Panel access
granted to the admin role), and emails the admin a single-use **set-password
link** (`App\Services\PasswordSetupService`, 48h, `password_setup_tokens` in the
landlord DB). The admin can't sign in until they follow it. On failure it drops
the half-built database, user and tokens and marks the tenant `failed` with
`provision_error`; re-submitting the same clinic name retries it.

The public set-password endpoints (`GET/POST /api/set-password`) have no session;
the SPA page is `/set-password/:token`. If the link expires or is lost, `/sa/tenants`
shows a **Resend setup link** action per tenant whose admin hasn't set up
(`POST /api/landlord/tenants/{id}/resend-setup-link`).

The **slug** and **database name** are derived from the clinic name — `Str::slug()`,
capped at 50 chars, with `mysmyle_` + underscored slug for the database
(`config/tenancy.db.name_prefix`). The name and the admin email are both checked
synchronously, so a clash returns a `422` before anything is created.

CLI equivalents:

```sh
php artisan tenant:create "Clinic B"                            # slug + db name derived from the name
php artisan tenant:create "Clinic B" clinic-b mysmyle_clinic_b  # or pass them explicitly
php artisan tenant:migrate [slug] [--pretend]                   # run tenant migrations across active tenants
php artisan tenant:provision-db-user [slug] [--force]           # give existing tenants a scoped MySQL user
php artisan tenant:backfill-control-panel [slug]                # grant CP to existing admin roles/users
```

`tenant:create` is infra-only and synchronous — it does not seed an admin user.
`slug` and `db_name` are optional; omit them to derive from the name.

After adding a file to `database/migrations/tenant/`, run `php artisan tenant:migrate`
to apply it to every existing tenant database.

## Authorization

Two layers gate a route:

1. **Module access** — `module.access:CP` (a system module) or a clinical module
   abbreviation. Checks `user_module_access.allowed`.
2. **Station/action permission** — `permission:<STATION_CODE>,<action>`. Checks the
   user's snapshotted `user_has_permissions`; the resolved code set is cached per
   user (`t{tenantId}:user_permissions:{userId}`, 6h TTL).

```php
Route::middleware('module.access:' . \App\Models\Landlord\Module::CONTROL_PANEL)
    ->group(function () {
        Route::middleware('permission:CP.STAFF,view')->group(fn () => /* ... */);
    });
```

The **Control Panel is split into four sections**, each a CP "station" with
view / add / edit actions (seeded by `StationSeeder::seedControlPanelStations`):

| Station      | Section              | Routes                                    |
|--------------|----------------------|-------------------------------------------|
| `CP.STAFF`   | Staff Members        | `/staff*`                                 |
| `CP.USERS`   | User Accounts        | `/users*` (incl. per-user role/access)    |
| `CP.ROLES`   | Departments & Roles  | `/departments*`, `/roles*`                |
| `CP.CATALOG` | Modules & Designations (read-only) | `/designations`, `/modules/{m}/stations` |

Managing a section usually needs sibling `view` grants too — the create-user
screen reads `/staff` and `/roles` for its pickers, so a user with `CP.USERS,add`
also needs `CP.STAFF,view` + `CP.ROLES,view`.

**Deploying the CP permissions to an existing environment:**

```sh
php artisan db:seed --class=StationSeeder      # adds the CP.* stations/permissions (idempotent)
php artisan cp:backfill-permissions            # grants them to users/roles that already have CP module access
php artisan cache:clear                        # drop any stale permission-code cache entries
```

Without the backfill, everyone with CP access is locked out (the permission rows
don't exist yet). The command is idempotent and also updates role templates so a
later resync keeps the CP permissions.

Role templates do **not** auto-propagate to assigned users (snapshot model).
`POST /api/roles/{roleId}/resync` pushes a role's current template to all current
holders.

A **system** module (CP) carries its stations with it: allowing/denying it on a
role or user (`PUT /api/roles/{id}/modules/{moduleId}/access`,
`PUT /api/users/{id}/modules/{moduleId}/access`) also grants/revokes all of that
module's station permissions (`App\Services\ModuleAccessService`). Clinical
modules are untouched — their stations are picked individually.

## User account passwords

**Policy:** one global `password_policy` landlord setting (`/sa/settings`,
`config/password_policy.php` fallback). `App\Support\PasswordPolicy` +
`App\Services\PasswordGenerator` + `App\Rules\CompliesWithPasswordPolicy`.

Account creation (`POST /api/users`) and reset (`POST /api/users/{id}/password/reset`,
both `CP.USERS`) branch on account type:

- **Provisioning admin + staff accounts:** no usable password — a set-password
  link is emailed (`SetPasswordLinkMail`) and the user chooses their own via
  `/set-password/:token`. Not flagged `must_change_password`. For a staff account
  the link goes to `staff.personal_email` (`required` on staff create/edit),
  while `users.email` is the sign-in address; the token is keyed to the login
  email. A reset **clears** the password (old one stops working) and re-issues.
- **Guest accounts** (no `staff_id`): a policy-compliant temp password is
  generated, emailed (`TemporaryPasswordMail`) to the login email, returned once
  for the admin, and the account is flagged `must_change_password` → locked to
  `/change-password` (enforced by `password.set` middleware) until the user sets
  their own via `POST /api/password`.

`PUT /api/users/{id}` never touches the password. Existing sessions are not
invalidated.

## Email

Outbound mail is transactional only (provisioning welcome, set-password link,
temporary passwords). Every mailable extends `App\Mail\TransactionalMail`
(`ShouldQueue`), so **the queue worker delivers email** — same `php artisan
queue:work` that runs provisioning.

- **Dev:** `MAIL_MAILER=smtp` → **Mailpit** (bundled with Laragon,
  `C:\laragon\bin\mailpit\…\mailpit.exe`; Laragon starts it). SMTP `127.0.0.1:1025`,
  web UI **http://localhost:8025**. Nothing leaves the machine. A `queue:work`
  worker must be running for mail to actually be delivered to Mailpit.
  (Set `MAIL_MAILER=log` instead to dump rendered emails to
  `storage/logs/laravel.log` with no worker.)
- **Prod:** `MAIL_MAILER=smtp` with the cPanel mailbox credentials, a real
  `MAIL_FROM_ADDRESS` on the sending domain, SPF/DKIM configured, and the queue
  worker under Supervisor.

Links in email point at the SPA, not the API — build them with
`App\Support\Frontend::url('/path')` (reads `config('app.frontend_url')` ←
`FRONTEND_URL`), never `url()` / `route()`.

## API auth from the shell (PowerShell)

```powershell
curl.exe -c cookies.txt http://localhost:8000/sanctum/csrf-cookie

$token = (Get-Content cookies.txt | Select-String "XSRF-TOKEN").ToString().Split("`t")[-1]
$token = [System.Uri]::UnescapeDataString($token)

curl.exe -b cookies.txt -c cookies.txt -X POST http://localhost:8000/api/login `
  -H "Content-Type: application/json" `
  -H "Accept: application/json" `
  -H "Origin: http://localhost:5173" `
  -H "X-XSRF-TOKEN: $token" `
  -d '{\"email\":\"admin@clinicb.test\",\"password\":\"password\"}'
```

`cookies.txt` files are throwaway session artifacts — git-ignored, safe to delete.
