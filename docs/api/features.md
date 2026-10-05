# SalesHub v2 — Feature endpoints (Phase 5)

Analytics, search, client notes and timeline, CSV import and export, and payment reminders. Conventions (envelope, errors, value formats) are in [conventions.md](conventions.md); sign-in security is in [auth-and-permissions.md](../architecture/auth-and-permissions.md). Each module has its own route file in `backend/routes/api/`, loaded inside the `auth:sanctum`, `active`, `ip.allowed` group.

## Analytics overview

`GET /api/analytics/overview` (`routes/api/analytics.php`, `Analytics\OverviewController`, `App\Analytics\{OverviewReport,AnalyticsScope,DateRange}`). One call returns everything the dashboard shows.

| Param | Notes |
|-------|-------|
| `from`, `to` | `YYYY-MM-DD`, inclusive. Default: the last 30 days ending today. `to` must not be before `from`; the range may not exceed 366 days (422 on `to`). |
| `team_id` | Optional team filter. |
| `user_id` | Optional single-person filter. |

**Rate limit.** 30 requests per minute per user (429 with `Retry-After`).

**Permissions and scope.** Any of `reports.view-all`, `reports.view-team` or `reports.view-own` (else 403). The broadest tier decides the scope: all, the user's own team, or only the user's own records. A `team_id` or `user_id` outside the tier is 403. Every query starts from the model's `visibleTo($user)` scope and is then narrowed by owner, so numbers match what the user can open.

**Response (`data`).** Money uses the standard `{amount_cents, currency, formatted}` shape; currency is `USD` (the demo data is single-currency).

| Section | Contents |
|---------|----------|
| `range` | `from`, `to`, `previous_from`, `previous_to`, `bucket`, `days`. The previous range is the same length, directly before. |
| `kpis` | `revenue_collected`, `won_value`, `average_order_value` (money), `won_count`, `new_leads`, `conversion_rate` (each `{value, previous, change_pct}`; `change_pct` is null when the previous value is 0), plus `overdue_payments` `{count, amount}`, `pending_approvals` `{reviewable, submitted}` and `active_clients` (count). |
| `funnel` | Leads by stage (pipeline order) for leads contacted in the range. |
| `revenue_series` | Points `{date, collected_cents, won_cents}`, one per bucket. Plain cents so charts can use them directly. |
| `leaderboard` | Top 5 sales executives by revenue collected, then won leads (`user`, `collected`, `won_leads`). `null` when the scope is a single person (own tier or a `user_id` filter). |
| `account_health` | Platform accounts by standing, or `null` when the user cannot view platform accounts. |
| `upcoming_payments` | Next 5 scheduled payments due today or later. |

**Metric definitions**

- Revenue collected: payments with status `paid` and `paid_at` in the range.
- Won: leads in stage `won` whose `stage_changed_at` is in the range; won value sums their estimated value.
- New leads: leads with `contacted_on` in the range.
- Conversion rate: of those new leads, the percentage that are `won` now (one decimal; 0 when there are none).
- Average order value: mean order total for orders with `ordered_on` in the range, excluding cancelled and refunded orders.
- Overdue payments, pending approvals, active clients and account health are current snapshots, not range based.
- Buckets: `day` for ranges up to 31 days, `week` up to 120 days, otherwise `month`. Weeks start on Monday; the first bucket never starts before `from`. Empty buckets are included as zeros.

## Search

`GET /api/search?q=term` (`routes/api/search.php`, `Search\SearchController`, `App\Search\RecordSearch`). `q` is required, 2 to 100 characters. Throttled to 60 requests per minute. It powers the command palette.

`data` is a list of groups `{key, label, hits}` in the order clients, leads, orders, platform-accounts, with up to 5 hits each: `{type, id, title, subtitle, url}` (`url` is a SPA path). A group appears only when the user may `viewAny` that model, and hits come from `visibleTo($user)` rows. Matching is a case-insensitive contains (LIKE wildcards in the term are escaped) over identifiers only: client name and Discord username, order number, platform account email and Discord username. Credentials are never searched or returned.

## Client notes and timeline

`routes/api/client-notes.php`, `ClientNotes\ClientNoteController`, `ClientNotes\ClientTimelineController`, `ClientNotePolicy`.

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/clients/{client}/notes` | Paginated; pinned first, then newest. Each note has `can_edit` and `can_delete` for the signed-in user. |
| POST | `/api/clients/{client}/notes` | `body` (required, max 2,000), `is_pinned`. 201. |
| PATCH | `/api/clients/{client}/notes/{note}` | `body` (author only), `is_pinned` (anyone who can view the client). |
| DELETE | `/api/clients/{client}/notes/{note}` | Author or a user with `clients.update`. 204. |
| GET | `/api/clients/{client}/timeline` | Paginated (`page[number]`, `page[size]`), newest first. |

Anyone who can view the client can read and add notes. Notes are additive and are not routed through approvals. A note on a client outside the route binding (wrong client) is 404 through scoped bindings.

Timeline items: `{id, kind: "note"|"activity", at, actor: {id, name}|null, summary, link}`. It merges the client's notes with activity-log entries for the client and for its leads, orders and payments that the user may see (soft-deleted ones included), for example "Order SH-2026-00001 updated: status".

## Imports

`routes/api/imports.php`, `Imports\{ImportController,ImportPreviewController,ImportStartController}`, `App\Imports\*`, `ProcessImport` job, `ImportPolicy`. Types: `leads` and `clients`, each needing `{type}.import` (admin and support).

Flow: upload, map columns, preview, start, poll.

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/imports/templates/{type}` | Template CSV download. |
| GET | `/api/imports` | The user's imports, newest first (admins see everyone's). Row errors are left out of the list. |
| POST | `/api/imports` | Multipart: `type`, `file`. 201 with the import plus `sample_rows` (first 5) and `suggested_mapping`. |
| GET | `/api/imports/{import}` | The import with `errors`. Owner or admin only. |
| POST | `/api/imports/{import}/preview` | Body `{mapping}`. Checks the first 50 rows with the real create rules and writes nothing. |
| POST | `/api/imports/{import}/start` | Body `{mapping}`. Saves the mapping, queues `ProcessImport` and answers **202** with the import. |
| GET | `/api/imports/{import}/errors` | CSV of the failed rows with an extra `Error` column. |

**Upload limits.** `.csv` or `.txt`, at most 2 MB, UTF-8 (BOM allowed), comma or semicolon delimiter (detected from the header line), at most 2,000 data rows, at least one data row. Blank rows are skipped. Row numbers match a spreadsheet (header is row 1). Violations are 422 on `file`. Files are stored on the private `local` disk under `imports/` and the path is never returned.

**Mapping shape.** `{ "CSV header": "field_key" | null }`: every header may be present; `null` or empty skips a column. An unknown header or field, a field mapped twice, or a missing required field is 422 on `mapping`. The import resource lists `fields` (`key`, `label`, `required`, example, help) for the type. Leads require `client_discord_username` (an existing client is reused, otherwise one is created); clients require `discord_username`.

**Preview** returns `{rows: [{row, values, valid, errors}], summary: {total_rows, checked, valid, invalid}}`. For clients it also flags duplicate Discord usernames inside the file.

**Processing.** `ProcessImport` runs on the queue (`php artisan queue:work`), one row at a time through the same rules and create actions as the UI (ownership and defaults match). Status goes `uploaded`, `queued`, `processing`, `completed` (or `failed`); counters `processed_rows`, `created_rows`, `failed_rows` update every 20 rows, so the SPA polls `GET /api/imports/{import}`. At most 200 row errors are stored (`{row, column, field, message}`); the error CSV is capped the same way. The first error of each failed row also stores that row's values (internal, never in the API), so the error CSV is built without the uploaded file: **the file is deleted as soon as the import completes or fails**. `preview` and `start` on an import that has already started are **409**; `start` moves `uploaded` to `queued` with one guarded update, and the job moves `queued` to `processing` the same way, so two concurrent starts or a duplicate job cannot run an import twice. Start and finish are written to the activity log (`import`).

**Limits and housekeeping.** Upload, preview and start share a limit of 10 requests per minute per user (429). Uploads that are never started are pruned with their file after 24 hours (`model:prune` for `App\Models\Import`, daily at 03:00; `IMPORTS_PRUNE_AFTER_HOURS`).

## Exports

`GET /api/exports/{type}` (`routes/api/exports.php`, `Exports\ExportController`), `type` is `leads` or `clients`. Needs `reports.export` and `viewAny` on the model. It accepts the same `filter[...]` and `sort` parameters as the index endpoint and exports only rows in the user's `visibleTo` scope. The CSV is streamed in chunks of 500 rows, capped at **10,000 rows**, UTF-8 with a BOM, named `{type}-{date}.csv`. Credentials and other hidden columns are never in a column list. Cells starting with `=`, `+`, `-`, `@`, tab or carriage return get a leading single quote (`CsvSanitizer`), so spreadsheets treat them as text; the same sanitizer writes the import error CSV. Each export is logged under `export` with the type, row count, filters and sort. Limited to 5 exports per minute per user (429); responses are `Cache-Control: no-store`.

## Payment reminders

`php artisan payments:send-reminders {--days=3}` (`App\Console\Commands\SendPaymentReminders`) is scheduled in `routes/console.php` daily at 08:00 without overlapping. It finds scheduled payments that are overdue or due within `--days` days, whose order owner is active, and notifies the owner with `PaymentDueReminder`. It skips a payment that already has a reminder created today, so running it twice is safe.

`PaymentDueReminder` is queued and goes to the notifications bell (`database`) and by email when the user has an address. Database payload: `{payment_id, order_id, order_number, due_date, amount_cents, currency, overdue, message}`. Needs a running scheduler (`php artisan schedule:work` locally) and queue worker (`php artisan queue:work`).

## Scheduled housekeeping and the demo reset

`routes/console.php` also schedules (run `php artisan schedule:work` locally, or `schedule:run` every minute from cron in production):

| Command | When | What |
|---------|------|------|
| `model:prune --model=App\Models\Import` | Daily 03:00 | Deletes never-started uploads older than 24 h and their files. |
| `activitylog:clean --force` | Daily 03:15 | Deletes audit-log entries older than `activitylog.clean_after_days` (`ACTIVITY_LOG_RETENTION_DAYS`, default 180). |
| `demo:reset` | Hourly, **only when `DEMO_MODE=true`**, without overlapping | `migrate:fresh --seed --force`, `cache:clear`, deletes `storage/app/private/imports/*`. Every visitor is signed out. Refuses to run when demo mode is off unless `--force` is passed (`App\Console\Commands\ResetDemoCommand`, `App\Actions\Demo\ResetDemo`). |
