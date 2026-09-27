# Admin Runbook

## 1. Environment Requirements

- XAMPP or equivalent LAMP stack
- PHP 7.4+
- MySQL/MariaDB

## 2. Initial Setup

1. Place project in `c:\xampp\htdocs\saleshub`.
2. Start Apache and MySQL.
3. Start Apache and MySQL (database is auto-created by import).
4. Import `database/saleshub.sql` (contains schema and demo data).
5. Configure DB credentials in `config/config.php`.
6. Open `http://localhost/saleshub/`.

Demo logins (password `Demo@123`): admin, support, tl, agent1, agent2

## 3. Core Configuration

File: `config/config.php`

Key settings:
- `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`
- Allowed IP ranges (`$allowed_ip_ranges`)
- `is_ip_allowed()` CIDR check

Runtime DB settings:
- MySQL timezone set to `+05:00`
- Charset set to `utf8`

## 4. Access Control Model

Auth/session:
- `includes/login_process.php`
- `includes/logout.php`

Device restrictions:
- `includes/device_helper.php`
- Current config blocks mobile for all listed roles

Role checks:
- Enforced at page level across `dashboards/*.php`

IP restrictions:
- Enforced on `sales_executive` and `tl` flows
- Several support checks are present but commented out in dashboard modules

## 5. Data Model Summary

Primary tables:
- `users`
- `accounts`
- `leads_data`
- `client_retention`
- `socials_data`
- `approvals`

Schema source of truth:
- `database/saleshub.sql`

Logical relationships:
- `users.pc_number` / `users.username` maps to `accounts.pc_number`
- `accounts.discord_email` scopes sales-facing records
- `approvals` stores deferred actions for core modules

## 6. Approval Workflow Operations

Queue page:
- `dashboards/support_tickets.php`

History page:
- `dashboards/approvals_history.php`

Behavior:
- Sales-facing update/delete actions create `approvals` entries
- Admin/support review and apply changes
- `accounts.pending_standings` is cleared when standings requests are approved/rejected

Polling endpoint:
- `GET /api/check_approvals.php` (requires logged-in admin or support user; 401 if unauthorized)

## 7. Scheduled/Manual Maintenance

CLI script:
- `scripts/update_status.php` — PHP CLI only (`php scripts/update_status.php`); returns 403 over HTTP

What it does:
- Sets `accounts.status = 1` when `pc_number` exists, otherwise `0`

Recommendation:
- Run only from trusted CLI; do not expose over HTTP

## 8. CSV Operations and Data Hygiene

CSV imports available in:
- `dashboards/accounts_management.php`
- `dashboards/leads_data_management.php`
- `dashboards/client_retention_management.php`
- `dashboards/socials_data_management.php`

CSV exports available in:
- `dashboards/accounts_management.php`
- `dashboards/client_retention_management.php`

Operational guidance:
- Validate headers against module expectations
- Keep a pre-import DB backup
- Use filtered exports for verification after import

## 9. Known Risks

1. Support IP checks are partially commented out.
2. Runtime `CREATE TABLE/ALTER TABLE` patterns can hide schema drift.

## 10. Hardening Checklist

- Enforce HTTPS
- Enforce IP policy consistently for required roles
- Add CSRF protection on all mutating forms
- Add centralized app and DB audit logging
- Lock down utility scripts and setup routes
- Move to controlled DB migrations/versioning
- Sanitize any seed/dump data before sharing

## 11. Troubleshooting

Login issues:
- Verify DB connectivity in `config/config.php`
- Confirm user exists with valid hashed password in `users`
- Verify session/cookie behavior

Access denied:
- Confirm client IP matches allowed CIDR in `config/config.php`
- Verify role for target dashboard

Approval not processing:
- Check row exists in `approvals` with `status='pending'`
- Verify reviewer role is `admin` or `support`

Missing sales records:
- Confirm account assignment (`accounts.pc_number`)
- Confirm active account status (`accounts.status = 1`)

## 12. Key File Map

- `index.php`
- `config/config.php`
- `includes/device_helper.php`
- `includes/login_process.php`
- `dashboards/support_tickets.php`
- `dashboards/approvals_history.php`
- `database/saleshub.sql`
- `scripts/update_status.php`
