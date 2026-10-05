# SalesHub v2 — API conventions

Applies to every endpoint under `/api`. Companion to [module-guide.md](module-guide.md) and the architecture docs.

Phase 5 feature endpoints (analytics, search, client notes and timeline, imports and exports, payment reminders): [features.md](features.md).

## Transport and auth

- JSON in and out. Clients send `Accept: application/json`.
- Sanctum SPA cookie auth (see [auth-and-permissions.md](../architecture/auth-and-permissions.md)). Every module route sits in the group `auth:sanctum`, `active`, `ip.allowed`.
- `PUT` and `PATCH` are both partial updates: only the fields that are sent change.

## Response envelope

| Case | Status | Body |
|------|--------|------|
| Single resource | 200 | `{ "data": { ... } }` |
| Created | 201 | `{ "data": { ... } }` |
| List | 200 | `{ "data": [ ... ], "links": { first, last, prev, next }, "meta": { current_page, from, last_page, links, path, per_page, to, total } }` (Laravel's standard paginator output) |
| Change queued for approval | 202 | `{ "data": <ApprovalRequest> }` |
| Deleted | 204 | empty |
| Small computed values | 200 | `{ "data": { ... } }`, e.g. `GET /api/approvals/pending-count` → `{ "data": { "reviewable": 3, "own": 1 } }` |

Relations appear only when loaded (`include=`) and use snake_case keys (`platformAccount` → `platform_account`).

## Pagination

`page[number]` (default 1) and `page[size]` (default 25, capped at 100; anything above is clamped). A plain `?page=2` also works as the page number. The `links` keep all other query parameters (filters, sort, include).

## Filtering, sorting, includes

Built with [spatie/laravel-query-builder](https://github.com/spatie/laravel-query-builder) v7. Every list query **starts from `Model::query()->visibleTo($user)`**, so a list never shows rows outside the user's scope.

| Parameter | Example | Notes |
|-----------|---------|-------|
| `filter[field]` | `filter[stage]=new,engaged` | Exact filters accept comma-separated values (`IN`). Names are the documented filter names, not always column names (`filter[owner]` → `owner_id`). |
| `filter[x_from]` / `filter[x_to]` | `filter[contacted_from]=2026-09-01` | Inclusive date bounds, `YYYY-MM-DD`; anything else → 422. |
| `filter[search]` | `filter[search]=pixel` | Case-insensitive "contains" over the endpoint's search columns, including related columns. `%` and `_` match literally; at most 100 characters (422 on `filter.search`). |
| `sort` | `sort=-contacted_on,id` | Whitelisted fields only; `-` prefix = descending. Each endpoint documents its default. |
| `include` | `include=client,owner` | Whitelisted relation names only (camelCase relation names). |

Unknown filters, sorts or includes → **400** `{"message": "Requested filter(s) `x` are not allowed. Allowed filter(s) are `...`."}`.

## Errors

| Status | When | Body |
|--------|------|------|
| 400 | Unknown filter/sort/include | `{ "message": "..." }` |
| 401 | Not signed in / deactivated | `{ "message": "Unauthenticated." }` |
| 403 | Missing permission, or the record is outside the user's scope (never 404 for existing records) | `{ "message": "This action is unauthorized." }` |
| 404 | ID does not exist (or is soft-deleted) | `{ "message": "..." }` |
| 409 | Approval already decided, or the record changed since the request was submitted | `{ "message": "...", "code": "approval_already_decided" \| "approval_failed" }` |
| 419 | CSRF token missing/expired (SPA refetches `/sanctum/csrf-cookie` and retries once) | `{ "message": "CSRF token mismatch." }` |
| 422 | Validation, including "record already has a pending change" and "no changes to submit" | `{ "message": "...", "errors": { "field": ["..."] } }` |
| 403 `demo_mode` | Public demo (`DEMO_MODE=true`): an action that would lock other visitors out of the shared accounts (password change, two-factor setup, signing out other sessions, changing a seeded account's password/username/email/role, or deactivating, activating or deleting it) | `{ "message": "The public demo shares its accounts, so ...", "code": "demo_mode" }` |
| 429 | Rate limited (see below) | `{ "message": "..." }`, header `Retry-After`; sign-in and two-factor also send `"code": "too_many_attempts", "retry_after": n` |

**Rate limits.** Sign-in and two-factor limits are in [auth-and-permissions.md](../architecture/auth-and-permissions.md) section 1.3/1.4. Expensive endpoints have named limiters keyed by the signed-in user (`App\Support\ApiRateLimits`): `GET /api/analytics/overview` 30 per minute, `GET /api/exports/{type}` 5 per minute, and `POST /api/imports`, `/imports/{id}/preview` and `/imports/{id}/start` 10 per minute together. Password change and "sign out other sessions" allow 6 per minute, the two-factor endpoints 10, and credential reveals 20.

**Security headers.** `App\Http\Middleware\SecurityHeaders` (global). API responses (`api/*`, `sanctum/*`) carry `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` and `Cache-Control: no-store, private` (CSV downloads keep their `Content-Disposition`). HTML pages get a strict same-origin CSP, and `/docs/*` its own (see [SECURITY.md](../../SECURITY.md) and [owasp-top-10.md](../security/owasp-top-10.md)). HSTS is sent on HTTPS outside the local environment.

Referenced IDs (`client_id`, `platform_account_id`, `owner_id`, ...) are validated against the user's visibility scope. An out-of-scope ID fails with the same message as a missing one: "The selected client id is invalid."

## Writes that may need approval (202)

Update and delete endpoints of approvable models follow one rule:

1. user can `update`/`delete` the record → the change is applied, **200** (or **204**);
2. else user can `requestChange` → an approval request is queued, **202** with the ApprovalRequest;
3. else **403**.

The body is validated the same way in both paths. An optional `reason` (max 500) is stored on the request. A record can have only one pending request; a second one returns **422** with `This record already has a pending change.`. Detail and list resources of approvable models include:

```json
"pending_change": null | { "id": 12, "action": {"value": "update", "label": "Update"}, "fields": ["stage"], "requested_by": {"id": 4, "name": "...", "username": "agent1"}, "requested_at": "2026-10-04T08:12:00Z" }
```

## Value formats

| Kind | Format | Example |
|------|--------|---------|
| Enum | `{ "value", "label" }` (null when empty) | `"stage": { "value": "portfolio_shared", "label": "Portfolio Shared" }` |
| Money | `{ "amount_cents", "currency", "formatted" }` (null when empty) | `{ "amount_cents": 123450, "currency": "USD", "formatted": "$1,234.50" }` |
| Date (calendar) | `YYYY-MM-DD` | `"contacted_on": "2026-10-04"` |
| Timestamp | ISO 8601, UTC, `Z` suffix | `"created_at": "2026-10-04T08:12:00Z"` |
| Foreign key | `{relation}_id` integer, always present | `"client_id": 7` |

Requests send enums as their `value`, money as `*_cents` integers plus `currency` (`^[A-Z]{3}$`), dates as `YYYY-MM-DD`. Secrets (encrypted columns) never appear in any resource; they are only returned by the dedicated `.../reveal` endpoints.
