# SalesHub v2 — Authentication & Permissions

Status: approved design for Phase 1. Companion to [data-model.md](data-model.md).

## 1. Authentication model

Sanctum **SPA cookie authentication** (session guard `web`, stateful API). The React SPA and the API share one origin in production; in development Vite (:5173) proxies `/api` and `/sanctum` to `php artisan serve` (:8000), so the browser also sees one origin. No bearer tokens are issued to the SPA (`personal_access_tokens` stays for future API clients).

### 1.1 Configuration

| File | Setting |
|------|---------|
| `bootstrap/app.php` | In `withMiddleware`: `$middleware->statefulApi();` (adds `EnsureFrontendRequestsAreStateful`: cookies, session, CSRF for requests from stateful domains). `$middleware->alias(['active' => EnsureUserIsActive::class, 'ip.allowed' => EnsureIpIsAllowed::class]);` `$middleware->trustProxies(at: env('TRUSTED_PROXIES'))` for production. |
| `config/sanctum.php` | `stateful` env default: `localhost,localhost:5173,localhost:8000,127.0.0.1,127.0.0.1:5173,127.0.0.1:8000`; production sets `SANCTUM_STATEFUL_DOMAINS=<app host>`. `guard => ['web']`. `expiration => null` (session lifetime governs). |
| `config/session.php` / `.env` | `SESSION_DRIVER=database`, `SESSION_LIFETIME=120`, `SESSION_EXPIRE_ON_CLOSE=false`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true` in production (false locally over http), `http_only=true`, `SESSION_SAME_SITE=lax`, `SESSION_DOMAIN=null`. |
| `config/cors.php` | Publish with `php artisan config:publish cors`. Same-origin means CORS is not exercised, but set `paths => ['api/*', 'sanctum/csrf-cookie']`, `allowed_origins => [env('FRONTEND_URL', 'http://localhost:5173')]`, `supports_credentials => true` so a split-origin deploy still works. |
| Frontend | HTTP client sends `credentials: 'include'`, `Accept: application/json`, and copies the `XSRF-TOKEN` cookie (URL-decoded) into `X-XSRF-TOKEN` on non-GET requests. On a 419 response: refetch the CSRF cookie and retry once. On 401: clear auth state and route to `/login`. |

### 1.2 Routes (`routes/api.php`, prefix `/api`)

| Method | Path | Middleware | Controller |
|--------|------|-----------|------------|
| GET | `/sanctum/csrf-cookie` | Sanctum default (`web`) | Sanctum `CsrfCookieController` → 204, sets `XSRF-TOKEN` |
| POST | `/api/auth/login` | `guest:web`, `throttle:login` | `Auth\LoginController` |
| POST | `/api/auth/logout` | `auth:sanctum` | `Auth\LogoutController` |
| GET | `/api/auth/me` | `auth:sanctum`, `active`, `ip.allowed` | `Auth\MeController` |
| PUT | `/api/auth/password` | `auth:sanctum`, `active`, `ip.allowed`, `throttle:6,1` | `Auth\PasswordController` |

All other API routes sit in a group with `auth:sanctum`, `active`, `ip.allowed`. Delete the scaffolded `GET /api/user` route.

**POST /api/auth/login** — `LoginRequest`:

```json
{ "login": "agent1 | agent1@example.com", "password": "string", "remember": false }
```

Rules: `login` required string max 255; `password` required string; `remember` boolean. If `login` contains `@`, match `email`, else `username` (both compared lowercase). Steps:

1. `Auth::guard('web')->attempt([$field => $login, 'password' => $password], $remember)`. On failure: hit the limiter, log `auth.login_failed`, return **422** `{"message": "These credentials do not match our records.", "errors": {"login": ["These credentials do not match our records."]}}`. Same message whether the user exists or not. Look the user up first; when none is found, still run `Hash::check($password, <constant dummy bcrypt hash>)` so response time does not reveal whether the identifier exists.
2. If `! $user->is_active`: `Auth::guard('web')->logout()`, log `auth.login_blocked_inactive`, return **403** `{"message": "This account has been deactivated. Contact an administrator.", "code": "account_inactive"}`. (Only reachable with the correct password, so it does not enable enumeration.)
3. IP allowlist check (section 5); on failure logout, log `auth.login_blocked_ip`, return **403** `{"message": "Sign-in is only allowed from the office network.", "code": "ip_not_allowed"}`.
4. `$request->session()->regenerate()`; clear the per-identifier limiter; `last_login_at = now()`, `last_login_ip = $request->ip()` (saved quietly, no activity entry); log `auth.login`.
5. Return **200** with the same body as `/me`.

**POST /api/auth/logout** — `Auth::guard('web')->logout()`, `session()->invalidate()`, `session()->regenerateToken()`, log `auth.logout`, return **204**.

**GET /api/auth/me** — **200**:

```json
{
  "data": {
    "id": 4, "name": "Ayla Mercer", "username": "agent1", "email": "agent1@example.com",
    "avatar_url": null, "is_active": true, "last_login_at": "2026-10-04T08:12:00Z",
    "roles": ["sales_executive"],
    "permissions": ["leads.view-own", "leads.create", "leads.request-change", "..."],
    "team": { "id": 1, "name": "Unit 1 Alpha", "floor": 3, "shift": "evening" },
    "workstation": { "id": 1, "code": "PC-01" }
  }
}
```

`permissions` = `$user->getAllPermissions()->pluck('name')->sort()->values()`. Built by `UserResource` + `MeResource`. Unauthenticated → **401** `{"message": "Unauthenticated."}`. The frontend uses `permissions` only for showing/hiding UI; the API always re-checks.

**PUT /api/auth/password** — `UpdatePasswordRequest`: `current_password` required + `current_password:web` rule; `password` required, `confirmed`, `Password::defaults()`, `different:current_password`. On success: save (hashed cast), delete the user's other rows in `sessions` (`where user_id = id and id != session()->getId()`), `session()->regenerate()`, clear `remember_token`, log `auth.password_changed`, return **204**.

### 1.3 Hardening

| Control | Specification |
|---------|---------------|
| Login limiter | `RateLimiter::for('login', ...)` in `AppServiceProvider` returns two limits: `Limit::perMinute(5)->by(Str::lower($login).'|'.$ip)` and `Limit::perMinute(20)->by($ip)`. Additionally a long window: `Limit::perHour(30)->by(Str::lower($login))` to slow distributed guessing on one account. |
| Lockout response | **429**, header `Retry-After: <seconds>`, body `{"message": "Too many login attempts. Please try again in 47 seconds.", "code": "too_many_attempts", "retry_after": 47}`; log `auth.login_locked_out` once per lockout. |
| Password policy | In `AppServiceProvider::boot`: `Password::defaults(fn () => Password::min(10)->mixedCase()->numbers()->symbols()->when(app()->isProduction(), fn ($r) => $r->uncompromised()))`. Used by password change and admin user create/update. |
| Inactive users | Blocked at login (above). Middleware `EnsureUserIsActive` on every authenticated route: if `! is_active` → logout, invalidate, **401** `{"message": "Unauthenticated."}`. Deactivating a user also deletes their `sessions` rows. Soft-deleted users cannot authenticate (default scope). |
| Session fixation | Regenerate on login and on password change; invalidate + regenerate token on logout. |
| CSRF | Enforced on all stateful non-GET requests via `statefulApi()`; login included. |
| Auth event logging | Activity log `auth` channel (data-model §7). Failed attempts store the submitted identifier, never the password. |
| Responses | Never return `password`, `remember_token`, or encrypted columns in any Resource. |

## 2. Roles

`RoleName` enum: `admin`, `support`, `team_lead`, `sales_executive`. Guard `web` for all roles and permissions. A user has exactly one role (enforced in `UserController` with `syncRoles`). Roles/permissions are created by `RolesAndPermissionsSeeder` (idempotent `firstOrCreate`, then `syncPermissions`), which runs in production deploys too, followed by `permission:cache-reset`.

**No `Gate::before` super-admin bypass.** Admin is granted every permission explicitly by the seeder (`Permission::all()`). Reason: business invariants must hold for admins too (cannot review own approval request, cannot deactivate or delete self, cannot remove the last active admin), and policy tests stay meaningful because admin goes through the same code paths.

## 3. Permissions

Convention `resource.action`, kebab-case. `view-all` / `view-team` / `view-own` are scope tiers: the broadest one the user holds decides the scope (section 4). `request-change` means "may submit update/delete requests to the approval queue for records in scope".

Legend: A = admin, S = support, TL = team_lead, SE = sales_executive.

| Permission | A | S | TL | SE |
|-----------|---|---|----|----|
| `dashboard.view` | ✓ | ✓ | ✓ | ✓ |
| `reports.view-all` | ✓ | ✓ | | |
| `reports.view-team` | ✓ | ✓ | ✓ | |
| `reports.view-own` | ✓ | ✓ | ✓ | ✓ |
| `reports.export` | ✓ | ✓ | ✓ | |
| `platform-accounts.view-all` | ✓ | ✓ | | |
| `platform-accounts.view-team` | ✓ | ✓ | ✓ | |
| `platform-accounts.view-own` | ✓ | ✓ | ✓ | ✓ |
| `platform-accounts.create` | ✓ | ✓ | | |
| `platform-accounts.update` | ✓ | ✓ | | |
| `platform-accounts.delete` | ✓ | ✓ | | |
| `platform-accounts.assign` (set workstation) | ✓ | ✓ | | |
| `platform-accounts.change-standing` | ✓ | ✓ | | |
| `platform-accounts.reveal-credentials` | ✓ | ✓ | | ✓ |
| `platform-accounts.request-new` | ✓ | ✓ | ✓ | ✓ |
| `platform-accounts.request-change` | | | ✓ | ✓ |
| `social-accounts.view-all` | ✓ | ✓ | | |
| `social-accounts.view-team` | ✓ | ✓ | ✓ | |
| `social-accounts.view-own` | ✓ | ✓ | ✓ | ✓ |
| `social-accounts.create` | ✓ | ✓ | | ✓ |
| `social-accounts.update` | ✓ | ✓ | | |
| `social-accounts.delete` | ✓ | ✓ | | |
| `social-accounts.reveal-credentials` | ✓ | ✓ | | ✓ |
| `social-accounts.request-change` | | | | ✓ |
| `clients.view-all` / `.view-team` / `.view-own` | ✓✓✓ | ✓✓✓ | –✓✓ | ––✓ |
| `clients.create` | ✓ | ✓ | ✓ | ✓ |
| `clients.update` | ✓ | ✓ | ✓ | |
| `clients.delete` | ✓ | ✓ | | |
| `clients.request-change` | | | ✓ | ✓ |
| `clients.import` (CSV import wizard) | ✓ | ✓ | | |
| `leads.view-all` / `.view-team` / `.view-own` | ✓✓✓ | ✓✓✓ | –✓✓ | ––✓ |
| `leads.create` | ✓ | ✓ | ✓ | ✓ |
| `leads.update` | ✓ | ✓ | ✓ | |
| `leads.delete` | ✓ | ✓ | | |
| `leads.reassign` (change owner) | ✓ | ✓ | ✓ | |
| `leads.request-change` | | | ✓ | ✓ |
| `leads.import` (CSV import wizard) | ✓ | ✓ | | |
| `orders.view-all` / `.view-team` / `.view-own` | ✓✓✓ | ✓✓✓ | –✓✓ | ––✓ |
| `orders.create` | ✓ | ✓ | ✓ | ✓ |
| `orders.update` | ✓ | ✓ | ✓ | |
| `orders.delete` | ✓ | ✓ | | |
| `orders.request-change` | | | ✓ | ✓ |
| `payments.create` (schedule/record) | ✓ | ✓ | ✓ | ✓ |
| `payments.update` | ✓ | ✓ | ✓ (not paid ones) | |
| `payments.delete` | ✓ | ✓ | | |
| `payments.request-change` | | | ✓ | ✓ |
| `services.view` | ✓ | ✓ | ✓ | ✓ |
| `services.manage` | ✓ | ✓ | | |
| `teams.view` | ✓ | ✓ | ✓ | ✓ |
| `teams.manage` | ✓ | | | |
| `workstations.view` | ✓ | ✓ | ✓ | ✓ |
| `workstations.manage` | ✓ | ✓ | | |
| `users.view` | ✓ | ✓ | ✓ (team) | |
| `users.create` | ✓ | ✓ | | |
| `users.update` | ✓ | ✓ | | |
| `users.deactivate` | ✓ | ✓ | | |
| `users.delete` | ✓ | | | |
| `users.manage-privileged` (touch admin/support users, assign admin/support roles) | ✓ | | | |
| `approvals.view-all` | ✓ | ✓ | | |
| `approvals.view-team` | ✓ | ✓ | ✓ | |
| `approvals.view-own` | ✓ | ✓ | ✓ | ✓ |
| `approvals.review-all` | ✓ | ✓ | | |
| `approvals.review-team` (lead, client, order, payment requests from own team) | ✓ | ✓ | ✓ | |
| `audit-log.view` | ✓ | | | |
| `notifications.view` | ✓ | ✓ | ✓ | ✓ |

Rows with three permissions use the order all/team/own; "–" means not granted. The seeder defines the matrix as `array<RoleName, list<string>>` in `App\Support\PermissionMatrix` so tests can assert against the same source. Support may `users.update`/`deactivate` only users whose role is `team_lead` or `sales_executive` (enforced in `UserPolicy` via `users.manage-privileged`).

## 4. Data scoping

### 4.1 Rules

"Team" = `user.team_id`; a team lead's team is `teams.team_lead_id = user.id` (seeded consistently with `team_id`).

| Model | view-all (A, S) | view-team (TL) | view-own (SE) |
|-------|-----------------|----------------|---------------|
| PlatformAccount | all | `workstation.team_id = team` | `workstation_id = user.workstation_id` |
| SocialAccount | all | via parent platform account | via parent platform account |
| Client | all | `owner.team_id = team` OR has a lead/order owned by a team member | `owner_id = user.id` OR has a lead/order with `owner_id = user.id` |
| Lead | all | `owner.team_id = team` | `owner_id = user.id` |
| Order (+items) | all | `team_id = team` (sale-time snapshot) OR `owner.team_id = team` | `owner_id = user.id` |
| Payment | all | via order | via order |
| ApprovalRequest | all | `requester.team_id = team` | `requested_by_id = user.id` |
| User | all (`users.view`) | `team_id = team` | self only (via `/me`) |
| Activity | `audit-log.view` only | — | — |

Unassigned platform accounts (`workstation_id` null) are visible only with `view-all`.

### 4.2 Implementation

- Trait `App\Models\Concerns\HasVisibilityScope` declaring `scopeVisibleTo(Builder $q, User $user): Builder`; each model implements its own body per the table, checking `view-all` → no constraint, else `view-team` → team constraint, else `view-own` → own constraint, else `whereRaw('1 = 0')`. Every index/list query starts with `Model::query()->visibleTo($request->user())`.
- Policies (auto-discovered, `app/Policies`). Each `view`/`update`/`delete` method checks the permission **and** `Model::query()->visibleTo($user)->whereKey($model->id)->exists()` through a shared helper `App\Policies\Concerns\ChecksVisibility::canSee(User, Model)`, so list scoping and single-record checks can never disagree.

| Policy | Methods |
|--------|---------|
| `PlatformAccountPolicy` | `viewAny`, `view`, `create`, `update`, `delete`, `revealCredentials`, `assign`, `changeStanding`, `requestNew`, `requestChange` |
| `SocialAccountPolicy` | `viewAny`, `view`, `create`, `update`, `delete`, `revealCredentials`, `requestChange` |
| `ClientPolicy`, `LeadPolicy`, `OrderPolicy` | `viewAny`, `view`, `create`, `update`, `delete`, `requestChange`; `LeadPolicy::reassign` |
| `PaymentPolicy` | `create(User, Order)`, `update` (false when paid unless S/A), `delete`, `requestChange` |
| `ServicePolicy`, `TeamPolicy`, `WorkstationPolicy` | `viewAny`, `view`, `create`, `update`, `delete` |
| `UserPolicy` | `viewAny`, `view`, `create`, `update`, `deactivate`, `delete`, `assignRole(User, User, RoleName)`; denies acting on self for `deactivate`/`delete`, denies removing the last active admin |
| `ApprovalRequestPolicy` | `viewAny`, `view`, `review` (not own; `review-all`, or `review-team` + requester in team + approvable type in {lead, client, order, payment}), `cancel` (own + pending) |
| `ActivityPolicy` | `viewAny` (`audit-log.view`) |

- Controllers resolve update vs. queue: `if ($user->can('update', $lead)) { apply } elseif ($user->can('requestChange', $lead)) { queue → 202 } else { 403 }`.
- Credential reveal is a separate endpoint `POST /api/platform-accounts/{id}/reveal` (and the social equivalent) with body `{"fields": ["discord_password"]}`, `throttle:20,1`, authorized by `revealCredentials`, returns the decrypted values and writes a `security.credentials_revealed` activity entry. Normal Resources never include secrets.

### 4.3 Forbidden vs. missing

Out-of-scope records return **403** `{"message": "This action is unauthorized."}`; nonexistent IDs return 404. Justification: every caller is an authenticated employee and IDs are already visible in reports and approval history, so hiding existence has little value; 403 keeps route-model binding simple, makes policy tests unambiguous, and lets the SPA show "You don't have access" instead of a misleading "not found". Lists never leak out-of-scope rows because they always use `visibleTo`.

## 5. Middleware

**`EnsureIpIsAllowed`** (alias `ip.allowed`) replaces v1 `is_ip_allowed()`. Config `config/saleshub.php`:

```php
'ip_allowlist' => [
    'enabled' => env('IP_ALLOWLIST_ENABLED', false),
    'ranges'  => array_filter(explode(',', env('IP_ALLOWLIST', '127.0.0.1,::1'))), // IPs or CIDRs
    'roles'   => ['sales_executive', 'team_lead'],
],
```

If enabled and the user has one of `roles` and `! IpUtils::checkIp($request->ip(), $ranges)` → logout the session, log `auth.login_blocked_ip`, **403** `{"message": "Sign-in is only allowed from the office network.", "code": "ip_not_allowed"}`. Admin and support are never restricted. Requires `trustProxies` configured behind a proxy so `ip()` is the client IP. The public demo keeps it disabled. The v1 mobile block is dropped (v2 is responsive).

**`EnsureUserIsActive`** (alias `active`) — see 1.3.

## 6. Pest test checklist (`tests/Feature/Auth`, `tests/Feature/Authorization`)

Auth
1. Login with email succeeds: 200, `/me` shape, session cookie set, `last_login_at` updated, `auth.login` logged.
2. Login with username succeeds; identifier is case-insensitive.
3. Wrong password → 422 generic message; unknown identifier → identical status and body.
4. 6th attempt within a minute for the same login+IP → 429 with `Retry-After` and `retry_after`; 21 attempts from one IP across different logins → 429.
5. Successful login clears the per-identifier counter.
6. Inactive user with the correct password → 403 `account_inactive`, not authenticated afterwards.
7. Deactivating a logged-in user → next request 401.
8. Login without CSRF token from a stateful origin (`Origin: http://localhost:5173`, `withCredentials`) → 419; with token → 200.
9. Session ID changes after login; logout → 204, then `/me` → 401.
10. `/me` unauthenticated → 401; authenticated returns roles, sorted permissions, team, workstation; never contains `password`.
11. Password change: wrong current → 422; weak password fails each rule (length 9, no symbol, no uppercase, no number); same as current → 422; success → 204, other sessions deleted, `auth.password_changed` logged.
12. `uncompromised` rule active only in production (assert via `Password::defaults()` in a production-env test).
13. IP allowlist enabled: sales exec from disallowed IP → 403 `ip_not_allowed`; admin from same IP → 200; disabled → 200.

Authorization
14. `PermissionMatrix` seeding: each role has exactly the matrix permissions; admin has all.
15. Sales exec lists platform accounts → only own workstation; viewing another workstation's account → 403.
16. Team lead sees team leads/orders only; another team's lead → 403.
17. Support/admin see all records, including unassigned accounts.
18. Credential reveal: sales exec on own account → 200 with values + one `security.credentials_revealed` entry (no secret in properties); on another workstation → 403 and no entry; team lead → 403.
19. Platform account JSON never contains encrypted fields.
20. Sales exec update of own lead → 202 + pending approval request, lead unchanged; second request for same lead → 422.
21. Sales exec create lead → 201 direct.
22. Team lead update of team lead → 200 direct; delete → 202 queued.
23. Approve applies change in a transaction, sets `applied_at`/`after`, clears `pending_key`; approving twice → 409; reviewer = requester → 403.
24. Stale approval (record edited after submission) → 409, status `failed`.
25. Reject requires comment, leaves record unchanged.
26. Team lead can review team lead/order requests, cannot review platform-account requests or another team's → 403.
27. Support cannot update/deactivate an admin or assign the admin role → 403; admin cannot delete self or deactivate the last admin → 403.
28. `audit-log` index: admin 200, support 403.
29. Sales exec `request-new` accounts → 202 approval of action `request_accounts`.

## 7. Open questions

- Demo password `Demo@1234` has 9 characters and fails the 10-character policy; this design uses `Demo@12345`. Confirm, or lower the minimum to 9.
- v1 used approvals as generic support tickets. Add a separate `tickets` module later, or drop it?
- Should sales executives reveal credentials (v1 showed them in plaintext to the workstation)? Currently yes, with audit logging.
