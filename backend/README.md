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

## API

**102 endpoints** across 19 modules. The interactive reference is at **`/docs/api`** while the server runs (generated from the code by Scramble). It's also committed as an OpenAPI 3.1 file: [`docs/api/openapi.json`](../docs/api/openapi.json).

| Module | Base path | Highlights |
|---|---|---|
| Auth | `/api/auth` | Login (email or username), logout, me, change password; two-factor challenge and setup, recovery codes, browser sessions list and sign-out-others |
| Leads | `/api/leads` | Pipeline stage moves (`/stage`), owner reassignment (`/owner`) |
| Clients | `/api/clients` | Client 360: leads, orders with balances, upcoming/overdue payments |
| Orders | `/api/orders` | Line items (`/orders/{id}/items`), totals in cents recalculated automatically |
| Payments | `/api/payments`, `/api/orders/{id}/payments` | Installments, `mark-paid`, due/overdue list |
| Platform accounts | `/api/platform-accounts` | Assign to workstation, change standing, request new, audited `reveal` |
| Social accounts | `/api/social-accounts` | Linked to platform accounts, audited `reveal` |
| Approvals | `/api/approvals` | Maker-checker queue: approve / reject / cancel, before→after diff, pending count |
| Users | `/api/users` | Role assignment, activate/deactivate (revokes sessions) |
| Teams · Workstations · Services | `/api/teams`, `/api/workstations`, `/api/services` | Org structure and service catalog |
| Audit log | `/api/audit-log` | Admin-only activity history (secrets redacted) |
| Notifications | `/api/notifications` | List, unread count, mark read (includes payment reminders) |
| Analytics | `/api/analytics/overview` | KPIs with previous-period comparison, funnel, revenue series, leaderboard, account health, scoped by `reports.view-*` |
| Search | `/api/search` | Grouped record search for the command palette (identifiers only, never credentials) |
| Client notes | `/api/clients/{id}/notes`, `/api/clients/{id}/timeline` | Pinned notes and a merged notes + activity timeline |
| Imports | `/api/imports` | CSV upload, column mapping, preview, queued run (202), error CSV; leads and clients |
| Exports | `/api/exports/{type}` | Streamed CSV of leads or clients, up to 10,000 rows, formula-injection safe |

**How edits work.** Users with `{resource}.update` change records directly (200). Users with only `{resource}.request-change`, for example sales executives, get **202 Accepted**, and their change waits in the approval queue until support or a team lead approves it. Approval locks the request, rejects stale or double approvals (409) and applies the change in a transaction.

Conventions (pagination, `filter[...]`, `sort`, `include`, money, enums, errors): [docs/api/conventions.md](../docs/api/conventions.md). Adding a module: [docs/api/module-guide.md](../docs/api/module-guide.md).

Design documents: [data model](../docs/architecture/data-model.md) · [auth & permissions](../docs/architecture/auth-and-permissions.md).

Details of the Phase 5 endpoints (analytics metrics, search, notes, imports, exports, reminders): [docs/api/features.md](../docs/api/features.md).

### Queue worker and scheduler

Two features run in the background, so start these next to `php artisan serve` (the `.env.example` default is `QUEUE_CONNECTION=database`):

```bash
php artisan queue:work      # CSV imports (ProcessImport) and PaymentDueReminder notifications
php artisan schedule:work   # runs payments:send-reminders every day at 08:00
php artisan payments:send-reminders   # or run the reminders once by hand (--days=3)
```

In production, run a queue worker under a process manager and call `php artisan schedule:run` every minute from cron.

### Environment notes

| Variable | Purpose |
|---|---|
| `DEMO_MODE` | `true` on the public demo: changing a password or turning on two-factor sign-in answers 403, because visitors share the demo accounts. Default `false`. |
| `SESSION_DRIVER` | Keep `database` (the default) so `GET /api/auth/sessions` can list and revoke browsers; other drivers show only the current session. |
| `QUEUE_CONNECTION` | `database` by default; imports stay `queued` until a worker runs. |

Uploaded import CSVs are stored on the private local disk under `storage/app/private/imports` (never publicly served; the path is not exposed by the API).

## Security highlights

- Credentials are encrypted at rest (`encrypted` casts) and never serialized. They can only be read through the reveal endpoints, which check permissions and write to the audit log.
- Login throttling per identifier+IP and per IP, generic failure messages, inactive-account blocking, session regeneration, and a strong password policy.
- Optional TOTP two-factor sign-in with replay protection, single-use recovery codes and a per-user lockout on wrong codes; sessions list exposes only HMAC ids.
- 71 permissions across 4 roles (`App\Support\PermissionMatrix`), enforced by Policies, with row-level scoping (`visibleTo`) so each role sees only its own, its team's, or all records.
- Optional office-network IP allowlist for agent roles (`IP_ALLOWLIST_ENABLED`).
- An audit trail of model changes and auth events (spatie/laravel-activitylog).

## Quality checks

```bash
composer lint      # Laravel Pint (code style)
composer analyse   # Larastan / PHPStan level 6
composer test      # Pest
```
