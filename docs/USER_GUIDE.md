# User Guide

## 1. Overview

This system is used by sales teams to manage:
- Assigned Discord accounts
- Leads pipeline
- Client retention records
- Social account credentials
- Approval tickets for controlled changes

## 2. Access by Role

### Admin
Main page: `dashboards/dashboard_admin.php`

Can access:
- User Management
- Accounts Management
- Approvals History
- Client Retention Management
- Leads Data Management
- Socials Data Management
- User Accounts Summary

### Support
Main page: `dashboards/dashboard_support.php`

Can access:
- User Management
- Accounts Management
- Support Tickets (approval queue)
- Client Retention Management
- Leads Data Management
- Socials Data Management
- User Accounts Summary

### Team Lead
Main page: `dashboards/dashboard_tl.php`

Current status:
- Dashboard cards are placeholders only (`#` links)

### Sales Executive
Main page: `dashboards/dashboard_sales_executive.php`

Can access:
- My Accounts
- Today's Client Retention Tasks
- Client Retention Form
- Leads Data Form
- Socials Data Form

## 3. Login and Access Rules

- Login page: `index.php`
- Authentication handler: `includes/login_process.php`
- Logout: `includes/logout.php`

Important behavior:
- Mobile access is blocked for all current roles.
- IP restriction (when `IP_RESTRICTION_ENABLED` is on) applies to `sales_executive` and `tl` pages.
- `admin` is not IP restricted.

## 4. Sales Executive Workflows

## 4.1 My Accounts
Page: `dashboards/agent_accounts.php`

Use this page to:
- View accounts assigned to your `pc_number`
- Filter by standings (`Active`, `Spam`, `Limited`, `Disabled`, `Violation`, `On Approval`)
- Submit account update requests (standings, `has_client`, password)
- Request new accounts

Notes:
- Update requests are queued in approvals.
- Pending standings appear under `On Approval`.

## 4.2 Leads Data Form
Page: `dashboards/leads_data.php`

Use this page to:
- Add new leads records
- View records for your assigned Discord emails
- Filter by date and Discord email
- Update/delete via approval queue

## 4.3 Client Retention Form
Page: `dashboards/client_retention_form.php`

Use this page to:
- Add client retention entries
- Track payments and expected upsales
- Capture nurturing status/comments
- Update/delete via approval queue

## 4.4 Today's Tasks
Page: `dashboards/client_retention_today.php`

Shows entries due today where either:
- `next_payment_date` is today, or
- `expected_next_upsale_date` is today

## 4.5 Socials Data
Page: `dashboards/socials_data.php`

Use this page to:
- Track social account credentials tied to a Discord email
- Mark if account is in use
- Submit updates/deletes through approval queue

## 5. Admin and Support Workflows

## 5.1 User Management
Page: `dashboards/user_management.php`

Use this page to:
- Create, edit, delete users
- Assign role and `pc_number`
- Filter/paginate users

## 5.2 Accounts Management
Page: `dashboards/accounts_management.php`

Use this page to:
- Search/filter full account inventory
- Add/edit/delete accounts
- Bulk upload accounts from CSV
- Download filtered CSV

## 5.3 Leads Data Management
Page: `dashboards/leads_data_management.php`

Use this page to:
- Manage all leads entries
- Bulk upload CSV
- Edit/delete entries (admin/support path)

## 5.4 Client Retention Management
Page: `dashboards/client_retention_management.php`

Use this page to:
- Manage all retention entries
- Bulk upload CSV
- Download filtered CSV

## 5.5 Socials Data Management
Page: `dashboards/socials_data_management.php`

Use this page to:
- Manage all socials entries
- Bulk upload CSV
- Edit/delete entries (admin/support path)

## 5.6 Support Tickets and Approvals
Page: `dashboards/support_tickets.php`

Use this page to:
- Review pending approval tickets
- Approve/reject with comments
- Process updates/deletes across `accounts`, `leads_data`, `client_retention`, `socials_data`

History page:
- `dashboards/approvals_history.php`

## 5.7 User Accounts Summary
Page: `dashboards/user_accounts_summary.php`

Use this page to:
- View per-agent totals and standings distribution
- Check last assigned date

## 6. CSV Features

Import modules:
- `dashboards/accounts_management.php`
- `dashboards/leads_data_management.php`
- `dashboards/client_retention_management.php`
- `dashboards/socials_data_management.php`

Export modules:
- `dashboards/accounts_management.php`
- `dashboards/client_retention_management.php`

## 7. Common User Issues

- Access denied on agent pages: check `IP_RESTRICTION_ENABLED` / `$allowed_ip_ranges` in `config/config.local.php`.
- Mobile blocked: use desktop/laptop.
- Missing data under sales pages: confirm account is assigned and active (`accounts.status = 1`).
- Update not visible immediately: check approval queue status.
