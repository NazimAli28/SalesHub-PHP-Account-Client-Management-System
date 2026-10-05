# SalesHub v2: OWASP Top 10 checklist

How SalesHub v2 handles each category of the [OWASP Top 10 (2021)](https://owasp.org/Top10/) and the [OWASP API Security Top 10 (2023)](https://owasp.org/API-Security/editions/2023/en/0x11-t10/). Paths are relative to the repository root. The reporting policy is in [SECURITY.md](../../SECURITY.md); the authentication and permission design is in [auth-and-permissions.md](../architecture/auth-and-permissions.md).

Last reviewed: 2026-10-06 (security review findings S1 to S12 fixed; see the end of this page).

## OWASP Top 10 (2021)

### A01 Broken Access Control

- Every module route sits in the `auth:sanctum`, `active`, `ip.allowed` group (`backend/routes/api.php`); there are no public data endpoints.
- One Policy per model, all role permissions from one matrix (`backend/app/Support/PermissionMatrix.php`). There is no `Gate::before` admin bypass, so admins pass through the same checks.
- Row-level scoping: `HasVisibilityScope::visibleTo($user)` (`backend/app/Models/Concerns/HasVisibilityScope.php`) limits every index, export, search, analytics query and policy check to own, team or all records. Out-of-scope records answer 403, never their data.
- Foreign keys in requests are validated with `App\Rules\VisibleTo` (`backend/app/Rules/VisibleTo.php`): nobody can attach another team's client, account or user, including a lead's `owner_id` and `closer_id`.
- Maker-checker approvals: a requester can never approve their own request, and decisions run under a row lock (`backend/app/Actions/Approvals/ApproveRequest.php`, `backend/app/Policies/ApprovalRequestPolicy.php`).
- Admin and support accounts can only be changed with `users.manage-privileged`; the last active admin cannot be deactivated or deleted (`backend/app/Policies/UserPolicy.php`). Your own password is changed only through `PUT /api/auth/password`, which re-checks the current one.
- Frontend redirects after sign-in go through `safeRedirect` (`frontend/src/features/auth/redirect.ts`), so no open redirect.

### A02 Cryptographic Failures

- Shared platform and social credentials use `encrypted` casts, are in `$hidden` and are only returned by the audited `/reveal` endpoints (`backend/app/Http/Controllers/PlatformAccountRevealController.php`), which log field names, never values.
- Passwords use bcrypt (12 rounds); TOTP secrets and recovery codes are encrypted columns and never serialized.
- Sessions are encrypted (`SESSION_ENCRYPT=true`), httpOnly, SameSite=Lax and Secure in production (`backend/.env.production.example`). HSTS (`max-age=31536000; includeSubDomains`) is sent on HTTPS outside local (`backend/app/Http/Middleware/SecurityHeaders.php`).
- Session IDs are never exposed: the sessions list shows a truncated HMAC (`backend/app/Http/Controllers/Auth/SessionsController.php`).

### A03 Injection

- Eloquent and bound parameters only; no concatenated user input in SQL. The two `whereRaw` LIKE helpers bind the term and escape `%`, `_` and `!` (`backend/app/Support/LikePattern.php`, used by `backend/app/Http/Filters/SearchFilter.php` and `backend/app/Search/RecordSearch.php`).
- Sorts, filters and includes are allowlisted per endpoint (spatie/laravel-query-builder, `backend/app/Http/Queries/*IndexQuery.php`); unknown ones answer 400.
- Blade views use escaped `{{ }}` only (no `{!! !!}`); React escapes by default and there is no `dangerouslySetInnerHTML` except the static chart theme style in `frontend/src/components/ui/chart.tsx`.
- CSV exports and the import error report neutralise spreadsheet formulas (`backend/app/Exports/CsvSanitizer.php`).

### A04 Insecure Design

- Mass assignment is closed: models declare `#[Fillable]` and controllers pass `$request->validated()` (Form Requests for every write).
- Shared demo accounts are a known risk of the public demo, so demo mode (`DEMO_MODE=true`) blocks every action that could lock other visitors out and resets the database hourly (`backend/app/Support/DemoAccounts.php`, `backend/app/Console/Commands/ResetDemoCommand.php`).
- Imports cannot run twice: `uploaded -> queued` and `queued -> processing` are single guarded updates (`backend/app/Http/Controllers/Imports/ImportStartController.php`, `backend/app/Jobs/ProcessImport.php`).

### A05 Security Misconfiguration

- Security headers on every response by kind of request (`backend/app/Http/Middleware/SecurityHeaders.php`):
  - API and CSV (`api/*`, `sanctum/*`): `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store, private`.
  - HTML (the SPA): `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; worker-src 'self' blob:; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests`, plus `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()`, `Cross-Origin-Opener-Policy: same-origin`, `Cross-Origin-Resource-Policy: same-origin`. The theme bootstrap script is an external file (`frontend/public/theme-init.js`) so no inline script is needed.
  - API reference (`/docs/*`): its own CSP with a per-request nonce for the inline scripts and the pinned, SRI-checked Stoplight Elements files from unpkg (`backend/resources/views/vendor/scramble/docs.blade.php`).
- Production template with `APP_DEBUG=false`, `LOG_LEVEL=warning`, Secure cookies, explicit `SANCTUM_STATEFUL_DOMAINS`, CORS limited to configured origins with no localhost fallback in production (`backend/config/cors.php`), and `TRUSTED_PROXIES` read from config (`backend/config/trustedproxy.php`) with a warning against `*`.
- Stored files live on the private disk and the path is never returned by the API.

### A06 Vulnerable and Outdated Components

- `composer audit` and `npm audit` run in CI (`.github/workflows/ci.yml`). The `shadcn` CLI package was removed because of dev-dependency advisories; its stylesheet is vendored.
- Third-party browser code on the docs page is pinned to an exact version with Subresource Integrity.

### A07 Identification and Authentication Failures

- Constant-time login: an unknown identifier still runs `Hash::check` against a dummy hash, and the error is the same generic 422 (`backend/app/Http/Controllers/Auth/LoginController.php`).
- Throttling (`backend/app/Support/LoginThrottle.php`): 5 failures per minute per login and IP, 30 failures per hour per login, 20 attempts per minute per IP. Keys are prefixed so a login name cannot collide with an IP key, only failures count (signing in never locks an account), and the demo accounts are never locked for an hour in demo mode.
- Session fixation: the session is regenerated on sign-in, two-factor completion and password change; logout invalidates it. A password change ends the user's other sessions.
- TOTP two-factor sign-in with replay protection (accepted time step cached per user), recovery codes compared with `hash_equals` and consumed atomically under a row lock, 5 wrong codes per minute per user and IP plus 10 per 15 minutes per user (`backend/app/Support/TwoFactorAuthenticator.php`). Turning it off needs the password and a current code or recovery code.
- Password policy: 10+ characters, mixed case, numbers and symbols, plus a breached-password check in production (`backend/app/Providers/AppServiceProvider.php`).
- Inactive users are blocked at sign-in and signed out on their next request (`EnsureUserIsActive`).

### A08 Software and Data Integrity Failures

- No `unserialize` of user input; queued jobs carry IDs only.
- CSRF protection for the cookie-authenticated API through Sanctum `statefulApi()`; the SPA retries once on 419.
- External scripts on the docs page are SRI-pinned; the SPA itself loads no third-party scripts.

### A09 Security Logging and Monitoring Failures

- Activity log (spatie/laravel-activitylog) for model changes, approvals, imports, exports, credential reveals and auth events (sign-in, failure, lockout, two-factor, sessions). Writers never pass secrets, and `ActivityResource` scrubs secret-looking keys before display (`backend/app/Http/Resources/ActivityResource.php`, `backend/app/Support/AuditLogger.php`).
- Failed sign-ins record the identifier only when it names an existing account, so a password typed into the login box is never stored.
- Retention: `activitylog:clean` runs daily (`ACTIVITY_LOG_RETENTION_DAYS`, default 180) (`backend/routes/console.php`).

### A10 Server-Side Request Forgery

- The application makes no outbound HTTP requests from user input. The only outbound call is the production password breach check (`Password::uncompromised()`, k-anonymity range query to a fixed host).

## OWASP API Security Top 10 (2023)

| Risk | How SalesHub handles it |
|------|-------------------------|
| API1 Broken Object Level Authorization | Policies on every route plus `visibleTo` row scoping; out-of-scope IDs in bodies fail `VisibleTo` validation like missing ones. |
| API2 Broken Authentication | Sanctum SPA cookies with CSRF, constant-time login, failure-only throttling with prefixed keys, TOTP with replay protection and lockouts, re-authentication for sensitive account actions. |
| API3 Broken Object Property Level Authorization | API Resources whitelist output; `$hidden` and encrypted casts for secrets; `#[Fillable]` + `validated()` for input; fields like `is_active` and `owner_id` are prohibited on generic updates and have dedicated, authorized endpoints. |
| API4 Unrestricted Resource Consumption | Page size at most 100; export capped at 10,000 rows; import capped at 2,000 rows; named rate limits (analytics 30/min, exports 5/min, imports 10/min per user, `backend/app/Support/ApiRateLimits.php`); search terms at most 100 characters; uploaded files deleted after processing and never-started uploads pruned after 24 hours. |
| API5 Broken Function Level Authorization | 71 permissions checked by Policies and Form Requests; privileged user management needs `users.manage-privileged`; role assignment is checked separately (`assignRole`). |
| API6 Unrestricted Access to Sensitive Business Flows | Maker-checker approvals for sensitive changes, no self-approval; credential reveals audited and limited to 20 per minute; demo-mode protections for the shared accounts. |
| API7 Server Side Request Forgery | No user-controlled outbound requests. |
| API8 Security Misconfiguration | Security headers per response kind, production env template, CORS allowlist, trusted proxies from config, debug off in production, Scramble dev tools off unless debugging. |
| API9 Improper Inventory Management | One route file per module; the OpenAPI spec is generated from the code (`docs/api/openapi.json`) and checked for leaked local paths; `/docs/api` can be turned off with `PUBLIC_API_DOCS=false`. |
| API10 Unsafe Consumption of APIs | No third-party APIs are consumed; CSV uploads are validated as UTF-8 and every row goes through the same validation and actions as the UI. |

## Security review fixes (2026-10-06)

| ID | Severity | Fix |
|----|----------|-----|
| S1 | High | Own password prohibited on `PATCH /api/users/{id}`; in demo mode the seeded accounts' password, username, email and role cannot change and they cannot be deactivated, activated or deleted (403 `demo_mode`). |
| S2 | High | Login limiter: prefixed keys, failures only, per-IP cap kept, demo accounts never locked for an hour in demo mode. |
| S3 | Medium | `SecurityHeaders` middleware; inline theme script moved to `frontend/public/theme-init.js`. |
| S4 | Medium | Demo mode lists only the current session, masks IPs (sessions and audit log) and blocks signing out other sessions; failed-login logs keep only real identifiers. |
| S5 | Medium | `demo:reset` (hourly in demo mode), import files deleted after processing, never-started uploads pruned, activity log cleaned daily. |
| S6 | Medium | Docs view overridden with exact versions, SRI and a nonce-based CSP. |
| S7 | Medium | `backend/.env.production.example`, config-driven trusted proxies, no CORS localhost fallback in production. |
| S8 | Low | Named rate limits for analytics, exports and imports. |
| S9 | Low | Per-user two-factor lockout, atomic recovery-code use, code required to turn two-factor off. |
| S10 | Low | LIKE wildcards escaped in `filter[search]`, 100-character cap. |
| S11 | Low | Atomic import state transitions. |
| S12 | Info | `closer_id` validated with `VisibleTo`. |

## Known limitations and accepted risks

- **`style-src 'unsafe-inline'`.** Recharts, Radix UI (positioning) and Sonner set inline styles, and the chart theme injects a static `<style>` element. Scripts stay `'self'` only, so the remaining risk is CSS injection, which React's escaping already makes unlikely.
- **Docs page third-party code.** `/docs/api` loads Stoplight Elements from unpkg. It is pinned with SRI, but the page also allows `'unsafe-inline'` styles and `https:` images. Set `PUBLIC_API_DOCS=false` to remove it from a deployment.
- **Shared demo accounts.** Anyone can sign in as any demo role, see the demo data and use the features. Demo mode prevents lock-outs and hides other visitors' sessions and addresses, and the hourly reset limits how long changes or uploaded content stay visible, but visitors can still see what others created within the hour.
- **Rate limits live in the cache.** With the database cache store they survive restarts; they are reset by `demo:reset` and are per application instance if a non-shared cache driver is used.
- **IP-based controls depend on `TRUSTED_PROXIES`.** A wrong value (especially `*` on a publicly reachable container) lets clients spoof their address and weakens rate limits, the audit log and the IP allowlist.
- **No account recovery by email.** There is no self-service password or two-factor reset flow (nothing to attack, but nothing to use either). An administrator can set a new password for another user; a user who loses both the authenticator and the recovery codes needs the two-factor columns cleared by an operator.
