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

| Command                           | What it does                                                                      |
| --------------------------------- | --------------------------------------------------------------------------------- |
| `npm run dev`                     | Vite dev server on :5173 with the API proxy                                       |
| `npm run build`                   | Type-checks, then builds into `dist/` (route-level code splitting, vendor chunks) |
| `npm run preview`                 | Serves the production build locally                                               |
| `npm run lint`                    | oxlint                                                                            |
| `npm run format` / `format:check` | Prettier (with Tailwind class sorting)                                            |
| `npm run typecheck`               | TypeScript, strict mode                                                           |
| `npm test` / `test:watch`         | Vitest + Testing Library, with the API mocked by MSW                              |
| `npm run api:types`               | Regenerates `src/api/schema.d.ts` from `../docs/api/openapi.json`                 |

## Environment

| Variable         | Default | Purpose                                                                                                                                              |
| ---------------- | ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `VITE_API_URL`   | empty   | API origin when it differs from the app's. Leave empty for same-origin production and for the dev proxy                                              |
| `VITE_DEMO_MODE` | `false` | `true` shows "Sign in as Admin / Support / Team Lead / Sales Executive" buttons on the login page (public demo only, with seeded fictional accounts) |

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
```

## Notes

- `src/api/schema.d.ts` and `src/components/ui/*` are generated. Regenerate them rather than editing by hand.
- `openapi-typescript` declares a TypeScript 5 peer dependency; `package.json` has an `overrides` entry that lets it use this project's TypeScript 6.
- The Leads list (`/leads`) is the reference implementation for list screens, forms and approval-aware actions.
