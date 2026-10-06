# SalesHub web app (frontend)

React 19 + TypeScript single-page app for SalesHub v2. It is built with Vite 8, Tailwind CSS 4 and shadcn/ui, and talks to the Laravel API with Sanctum cookie auth.

- Architecture: [../docs/frontend/architecture.md](../docs/frontend/architecture.md)
- How to build a screen: [../docs/frontend/screen-guide.md](../docs/frontend/screen-guide.md)

## Setup

```bash
npm install
cp .env.example .env.local   # optional, see Environment
npm run dev                  # http://localhost:5173
```

Run the API alongside it (`php artisan serve` in `../backend`, port 8000). The dev server proxies `/api` and `/sanctum` to it, so the browser sees a single origin and Sanctum's cookies just work.

## Scripts

| Command                           | What it does                                                                                 |
| --------------------------------- | -------------------------------------------------------------------------------------------- |
| `npm run dev`                     | Vite dev server on :5173 with the API proxy                                                  |
| `npm run build`                   | Type-checks, then builds into `dist/` (route-level code splitting, vendor chunks)            |
| `npm run preview`                 | Serves the production build locally                                                          |
| `npm run lint`                    | oxlint                                                                                       |
| `npm run format` / `format:check` | Prettier (with Tailwind class sorting)                                                       |
| `npm run typecheck`               | TypeScript, strict mode                                                                      |
| `npm test` / `test:watch`         | Vitest + Testing Library, with the API mocked by MSW                                         |
| `npm run api:types`               | Regenerates `src/api/schema.d.ts` from `../docs/api/openapi.json`                            |
| `npm run e2e` / `e2e:ui`          | Playwright end-to-end and axe accessibility tests (starts its own servers)                   |
| `npm run screenshots`             | Regenerates the README images in `../docs/screenshots` (starts the e2e servers)              |
| `npm run build:demo`              | Static browser demo for GitHub Pages (`--mode demo`, `.env.demo`): API mocked in the browser |
| `npm run preview:demo`            | Serves that build on :4180 under the Pages base path                                         |
| `npm run e2e:demo`                | Builds the browser demo and runs `e2e/static-demo.spec.ts` against it (no PHP)               |

## End-to-end tests

`npm run e2e` starts its own API on :8001 (PHP's built-in server against a fresh, seeded SQLite file `backend/database/e2e.sqlite`) and a Vite server on :5174, so your dev database is never touched. First run `npx playwright install chromium`.

- `PHP_BINARY`: path to PHP 8.3+ (defaults to `php`).
- `E2E_REUSE_SERVER=1` reuses running servers while you iterate; `E2E_VERBOSE=1` prints server logs.
- Specs live in `e2e/`: sign-in per role, leads and the board, approvals, Client 360 notes, CSV import, the command palette, the dashboard, keyboard checks and `a11y.spec.ts` (axe on every screen, light and dark).
- Tests run serially on one worker because they share one database. CI runs them in the `e2e` job and uploads the report when it fails.

## Browser demo (GitHub Pages)

`npm run build:demo` builds the real SPA with `VITE_STATIC_DEMO=true` and the base path `/SalesHub-PHP-Account-Client-Management-System/` (see `.env.demo`). `main.tsx` then lazy-loads `src/demo/start.ts`, which loads `src/demo/data/demo-data.json` (exported by `php artisan demo:export-static`), moves its dates to today, and starts an MSW service worker that answers every `/api` and `/sanctum` call in the browser:

- `src/demo/store.ts`: the in-memory tables, saved to sessionStorage after each write (a refresh keeps changes in that tab; the banner's **Reset demo data** clears them).
- `src/demo/permissions.ts`, `query.ts`, `present.ts`, `engine.ts`, `validate.ts`: the permission matrix and `visibleTo` scoping, the filter/sort/pagination engine, the Resource shapes, the approval rule (200/202/403) and maker-checker engine, and Laravel-style 422s.
- `src/demo/handlers/*`: one file per module. Imports answer `403 demo_mode`; exports build the CSV in the browser; analytics come from snapshots the real `OverviewReport` computed at export time.

The Vite plugin in `vite.config.ts` adds `mockServiceWorker.js` and a `404.html` copy (deep links) to the demo build only; normal builds and tests contain none of `src/demo`.

## Environment

| Variable           | Default | Purpose                                                                                                                                              |
| ------------------ | ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `VITE_API_URL`     | empty   | API origin when it differs from the app's. Leave empty for same-origin production and for the dev proxy                                              |
| `VITE_STATIC_DEMO` | `false` | `true` only in the browser demo build (`.env.demo`); `VITE_BASE_PATH` sets its base path                                                             |
| `VITE_DEMO_MODE`   | `false` | `true` shows "Sign in as Admin / Support / Team Lead / Sales Executive" buttons on the login page (public demo only, with seeded fictional accounts) |

The demo-mode banner ("Demo mode: shared demo accounts, data resets every hour") is driven by the API's `demo_mode` flag on `/auth/me`, not by a `VITE_*` variable; users can dismiss it for the session.

Only `VITE_*` variables reach the browser, so never put secrets in them.

## Screens

| Area           | Screens                                                        | Highlights                                                                                                        |
| -------------- | -------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| Overview       | Dashboard, **Today**, Approvals, Notifications                 | Daily list of due and overdue payments and follow-ups; approval queue with bulk actions and a before → after diff |
| Sales          | Leads, Clients (+ **Client 360**), Orders (+ detail), Payments | Server-side tables with URL-synced filters; order line items with live totals; payment schedule and mark-paid     |
| Accounts       | Platform accounts (+ detail), Social accounts                  | Write-only credentials; audited reveal panel that auto-hides after 30 s                                           |
| Administration | Users, Teams, Workstations, Services, Audit log                | Role rules mirrored in the UI; redacted audit diffs                                                               |
| Insights       | Dashboard                                                      | KPIs with period comparison and revenue, funnel, leaderboard and account-health charts; range and team in the URL |
| Pipeline       | Leads **Board**, Client notes and timeline, **Import**         | Drag-and-drop Kanban with keyboard moves and approval-aware rollback; CSV import wizard and CSV export buttons    |
| Account        | Profile **Security**                                           | Two-factor setup with recovery codes, browser sessions list; Ctrl+K search across records                         |

Every screen respects the user's permissions: navigation items, routes and row actions are hidden or guarded, and edits that need approval say "Send for approval" and handle the API's `202 Accepted`.

## Structure

```
src/
  app/          shell: providers, router, layouts, navigation config, theme, error pages
  api/          fetch client, ApiError, generated schema types, list params, query keys, useApiMutation
  features/     one folder per module (auth, dashboard, profile, leads, ...): api.ts, schemas.ts, components/, pages/
  components/   ui/ (shadcn), data-table/, form/, layout/, data-display/
  lib/          permissions, enums and status tones, formatting, roles
  hooks/        small generic hooks
  test/         Vitest setup, MSW handlers, fixtures, render helpers
  demo/         static browser demo only: in-browser API (MSW) and the exported data set
```

## Notes

- `src/api/schema.d.ts` and `src/components/ui/*` are generated. Regenerate them rather than editing by hand.
- `openapi-typescript` declares a TypeScript 5 peer dependency; `package.json` has an `overrides` entry that lets it use this project's TypeScript 6.
- The Leads list (`/leads`) is the reference implementation for list screens, forms and approval-aware actions.
