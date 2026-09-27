# API Endpoints

This project is mostly server-rendered PHP, but includes utility endpoints used by dashboard scripts and AJAX calls.

## 1. Authentication and Session

### `POST /includes/login_process.php`
Purpose:
- Authenticate user and start session

Input:
- `username`
- `password`
- `device_is_mobile` (hidden field)
- `device_detection` (hidden field)

Behavior:
- Verifies credentials from `users`
- Applies mobile restriction by role
- Sets session variables (`user_id`, `username`, `role`, `name`, `pc_number`)
- Redirects to role dashboard

### `GET /includes/logout.php`
Purpose:
- Destroy session and redirect to login

## 2. Approvals and Notifications

### `GET /api/check_approvals.php`
Purpose:
- Return pending approval list and count for polling

Auth:
- Requires logged-in admin or support user; returns 401 JSON `{"error":"Unauthorized"}` if not authenticated or role is insufficient

Response:
```json
{
  "count": 3,
  "approvals": [
    {
      "id": 101,
      "table_name": "accounts",
      "record_id": 999,
      "action": "update",
      "status": "pending"
    }
  ]
}
```

## 3. User and Sales Lookup Endpoints

### `POST /api/get_sales_info.php`
Purpose:
- Resolve `name` and `pc_number` from a `discord_email`

Auth:
- Requires any logged-in user; returns 401 JSON `{"error":"Unauthorized"}` if not authenticated

Input:
- `discord_email`

Logic:
1. Looks up `accounts.pc_number` by `discord_email` where `status=1`
2. Looks up `users.name` using `username = pc_number`

Response:
```json
{
  "name": "Agent Name",
  "pc_number": "U1P1Agent"
}
```

### `GET /api/get_user_details.php?user_id={id}`
Purpose:
- Return account counts for a specific user's `pc_number`

Auth:
- `admin` or `support` only; returns 403 if user lacks required role

Response shape:
```json
{
  "total": 0,
  "active": 0,
  "spam": 0,
  "limited": 0,
  "disabled": 0,
  "violation": 0
}
```

Note:
- Current implementation groups by `accounts.status`, so bucket mapping may not reflect standings correctly.

## 4. Client Retention Form AJAX

### `GET /dashboards/client_retention_form.php?fetch_pc=1&discord_email={email}`
Purpose:
- Fetch `pc_number` for selected Discord email in form UI

Response:
```json
{ "pc_number": "U1P2Agent" }
```

## 5. Support Ticket Submission Route

### `POST /dashboards/support_tickets.php`
Purpose:
- Submit support query ticket into `approvals` as `table_name='support_ticket'`

Input:
- `entry_id`
- `discord_email`
- `order_number`
- `query`

Auth:
- Any logged-in user can submit

Result:
- Redirects to `client_retention_form.php` with status query string

## 6. CSV Download Endpoints (Query-triggered)

These are page endpoints that return CSV when `download=csv` is set.

### `GET /dashboards/accounts_management.php?...&download=csv`
Returns:
- Filtered account records as CSV

### `GET /dashboards/client_retention_management.php?...&download=csv`
Returns:
- Filtered client retention records as CSV

## 7. Form Endpoints with Action-based POST

These pages process CRUD and bulk actions based on `POST` action values:
- `/dashboards/accounts_management.php`
- `/dashboards/agent_accounts.php`
- `/dashboards/leads_data.php`
- `/dashboards/leads_data_management.php`
- `/dashboards/client_retention_form.php`
- `/dashboards/client_retention_management.php`
- `/dashboards/socials_data.php`
- `/dashboards/socials_data_management.php`
- `/dashboards/support_tickets.php`

Typical action keys:
- `action=create|update|delete|bulk_upload`
- module-specific flags like `bulk_upload`, `request_new_accounts`, `update_account`

## 8. Maintenance Script Endpoints

### CLI: `php scripts/update_status.php`
Purpose:
- Batch update `accounts.status` from `pc_number` presence

Execution:
- PHP CLI only; returns 403 if accessed over HTTP

Output:
- Plain text success/error

## 9. Response and Error Conventions

Current behavior is mixed:
- JSON responses for AJAX/helper endpoints
- Redirects for form workflows
- Plain text for CLI maintenance scripts
- HTTP status codes used in API endpoints (401/403)

When building new integrations, verify each endpoint contract directly in source before client implementation.
