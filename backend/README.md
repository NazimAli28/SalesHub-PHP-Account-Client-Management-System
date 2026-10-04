# SalesHub API (backend)

Laravel 13 REST API for SalesHub v2. The single-page app authenticates with **Sanctum session cookies** (same domain, CSRF protected).

## Requirements

- PHP 8.3+ with `intl`, `zip`, `pdo_mysql`, `pdo_sqlite`, `sodium`
- Composer 2
- MySQL 8. The repo's `docker-compose.yml` provides it on host port **3307**. Tests use SQLite in memory.

## Setup

```bash
docker compose up -d          # from the repo root: MySQL :3307 + Mailpit :8025
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed    # schema + roles/permissions + ~6 months of fictional demo data
php artisan serve             # http://localhost:8000
```

### Demo logins

All demo users share the password **`Demo@12345`**.

| Username | Role |
|---|---|
| `admin` | Admin |
| `support`, `support2` | Support |
| `tl`, `tl2`–`tl4` | Team lead (one per team) |
| `agent1`–`agent6` | Sales executive |
| `agent7` | Sales executive, **deactivated** (login is refused) |

## API (so far)

| Method | Path | Notes |
|---|---|---|
| GET | `/sanctum/csrf-cookie` | Sets the `XSRF-TOKEN` cookie; call before login |
| POST | `/api/auth/login` | `{ login, password, remember }`. `login` accepts an email or a username. Rate limited |
| POST | `/api/auth/logout` | 204 |
| GET | `/api/auth/me` | Current user, role, permissions, team, workstation |
| PUT | `/api/auth/password` | Change own password; other sessions are signed out |
| POST | `/api/platform-accounts/{id}/reveal` | Decrypt selected credential fields; audit-logged |
| POST | `/api/social-accounts/{id}/reveal` | Same, for social accounts |

Design documents: [data model](../docs/architecture/data-model.md) · [auth & permissions](../docs/architecture/auth-and-permissions.md).

## Security highlights

- Credentials are encrypted at rest (`encrypted` casts) and never serialized. They can only be read through the reveal endpoints, which check permissions and write to the audit log.
- Login throttling per identifier+IP and per IP, generic failure messages, inactive-account blocking, session regeneration, and a strong password policy.
- 69 permissions across 4 roles (`App\Support\PermissionMatrix`), enforced by Policies, with row-level scoping (`visibleTo`) so each role sees only its own, its team's, or all records.
- Optional office-network IP allowlist for agent roles (`IP_ALLOWLIST_ENABLED`).
- An audit trail of model changes and auth events (spatie/laravel-activitylog).

## Quality checks

```bash
composer lint      # Laravel Pint (code style)
composer analyse   # Larastan / PHPStan level 6
composer test      # Pest
```
