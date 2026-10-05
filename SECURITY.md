# Security policy

## Supported versions

| Version | Supported |
|---------|-----------|
| v2 (Laravel API + React SPA, branch `v2`, later `main` from `v2.0.0`) | Yes |
| v1 (plain PHP, tag `v1.0`, branch `v1`) | No. Kept for reference only; it has known issues that v2 fixes |

## Reporting a vulnerability

Please do not open a public issue for a security problem. Email **security@example.com** with:

- what you found and where (endpoint, screen or file),
- steps to reproduce, and what an attacker could do with it,
- your name or handle if you would like to be credited.

You can expect an acknowledgement within a few days and an update once the issue is confirmed or ruled out. Please test only against your own local copy or the public demo with the shared demo accounts; do not run automated scans or load tests against the demo, and do not access data that is not yours.

## Security model in short

- **Authentication.** Laravel Sanctum SPA cookies: encrypted, httpOnly, SameSite=Lax session cookie, CSRF on every state-changing request, session regeneration on sign-in. Login throttling counts failures (per login and IP, per login per hour) plus a per-IP cap; optional TOTP two-factor sign-in with replay protection and single-use recovery codes. Details: [auth-and-permissions.md](docs/architecture/auth-and-permissions.md).
- **Authorization.** 71 permissions over 4 roles in one matrix (`backend/app/Support/PermissionMatrix.php`), enforced by Policies with row-level scoping (`visibleTo`). There is no admin bypass. Changes by some roles go through maker-checker approvals, and nobody can approve their own request.
- **Data protection.** Shared platform credentials are encrypted at rest, never serialized and only readable through audited reveal endpoints. Audit-log properties are scrubbed of secret-looking keys.
- **Transport and browser.** Security headers on every response (`backend/app/Http/Middleware/SecurityHeaders.php`): a strict same-origin Content Security Policy for the app, `default-src 'none'` for the API, HSTS on HTTPS, `nosniff`, frame denial, a restrictive Permissions-Policy.
- **Abuse limits.** Named rate limits on analytics, exports and imports; uploads are deleted after processing; the activity log is pruned.
- **Public demo.** `DEMO_MODE=true` protects the shared accounts (no password, 2FA, username, email or role changes; no deactivation or deletion; no "sign out other sessions"), hides other visitors' sessions and coarsens IP addresses, and resets the database every hour.

The full checklist against the OWASP Top 10 (2021) and the OWASP API Security Top 10 (2023), with known limitations, is in [docs/security/owasp-top-10.md](docs/security/owasp-top-10.md). Production settings are in [backend/.env.production.example](backend/.env.production.example).

© Nazim Ali. All rights reserved.
