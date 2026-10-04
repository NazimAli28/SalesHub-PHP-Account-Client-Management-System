# SalesHub v2: Frontend architecture

The SalesHub web app is a React 19 single-page app that talks to the Laravel API with Sanctum SPA cookie auth. This document covers how the app is organised and why. For a step-by-step recipe to build a new screen, see [screen-guide.md](screen-guide.md).

## 1. Stack

| Concern | Choice | Why |
|---------|--------|-----|
| UI | React 19, TypeScript 6 (strict), Vite 8 | Fast dev server, strict types end to end |
| Styling | Tailwind CSS 4 + shadcn/ui (Radix base, `radix-nova` style) | Accessible primitives whose source lives in our repo (`src/components/ui`) |
| Routing | React Router 8, **data mode** (`createBrowserRouter`) | Lazy route modules (code splitting), per-route `ErrorBoundary`, route `handle` for breadcrumbs, navigation state for the progress bar. v8 is the current major; its data-mode API is the same as v7's |
| Server state | TanStack Query 5 | Caching, background refetch, polling, `keepPreviousData` for paging |
| Tables | TanStack Table 9 | Headless; we use it for column, sort-header and selection state while the server does the data work |
| Forms | React Hook Form + Zod 4 (`@hookform/resolvers`) | Typed schemas, minimal re-renders |
| Dates / icons / toasts | date-fns 4, lucide-react, sonner | Small and tree-shakeable |
| API types | `openapi-typescript` → `src/api/schema.d.ts` | Generated from `docs/api/openapi.json`, so types follow the backend |
| Tests | Vitest 5, Testing Library, MSW 2 | MSW mocks `fetch` at the network level, so tests exercise the real client |

There is no global state library. Server data lives in TanStack Query; list state lives in the URL; the rest is local component state.

## 2. Folder structure

```
src/
  main.tsx                 entry: mounts <App />
  index.css                Tailwind, theme tokens (brand colour, light + dark)
  vite-env.d.ts            typed VITE_* env variables
  app/                     application shell
    App.tsx                creates the QueryClient + router, wires the global 401 handler
    AppProviders.tsx       Theme, Query, Auth, Tooltip, Toaster (+ devtools in dev)
    router.tsx             the route tree: lazy pages, guards, error boundaries, handles
    paths.ts               every URL in one place
    navigation.ts          sidebar config (label, icon, path, permission) + filterNavigation()
    query-client.ts        QueryClient defaults (no retry on 4xx)
    layouts/               AppLayout, AppSidebar, top bar parts, BrandMark
    pages/                 403, 404, ComingSoon, RouteErrorBoundary
    theme/                 ThemeProvider + no-flash helpers
  api/                     HTTP and data conventions (no React screens)
    client.ts              typed fetch client: CSRF, 419 retry, ApiError, 202, 401 listeners
    errors.ts              ApiError + friendly fallback messages
    types.ts               envelopes, Money, EnumValue, resource types narrowed from the schema
    schema.d.ts            GENERATED (npm run api:types). Do not edit
    list-params.ts         list state <-> URL <-> API query (page, size, sort, q, filters)
    query-keys.ts          createQueryKeys('leads') factory
    use-api-mutation.ts    useMutation + toasts + 422 -> form fields + 202 handling
  features/<module>/       one folder per business module
    api.ts                 query keys, query hooks, mutation hooks, option loaders
    schemas.ts             zod schema, form defaults, payload mapping
    components/            module-only components (columns, row actions, form sheet)
    pages/                 route pages (default export, lazy loaded)
  components/
    ui/                    shadcn/ui primitives (generated; restyle via tokens, not edits)
    data-table/            <DataTable> and its parts, useDataTableParams
    form/                  RHF-bound fields, FormDialog / FormSheet
    layout/                PageHeader, EmptyState, ErrorState, skeletons, ConfirmDialog
    data-display/          StatusBadge, MoneyText, RelativeTime
  lib/                     framework-free helpers: permissions, enums + tones, format, roles, csv
  hooks/                   generic hooks (debounce, countdown, use-mobile)
  test/                    Vitest setup, MSW server + handlers, fixtures, render helpers
```

Changes from the suggested layout, and why:

- `components/data-display/` holds value renderers (badges, money, dates). They are not layout pieces, and every table and detail page uses them.
- `app/pages/` holds the error and placeholder pages, which belong to the shell, not to any business module.
- `api/use-api-mutation.ts` sits next to the client because it encodes API conventions (202, 422) rather than UI.

## 3. Data flow

```
URL (?page=2&stage=new)  ──useDataTableParams──▶ ListParams
ListParams ──useLeads(params)──▶ queryKey leadKeys.list(params) ──fetchLeads──▶ api.get('/leads', toApiQuery(params))
api client ──fetch /api/leads?page[number]=2&filter[stage]=new──▶ Vite proxy (dev) / same origin (prod) ──▶ Laravel
response Paginated<Lead> ──▶ <DataTable query={leads}> ──▶ rows, pagination, empty / error states
user clicks a header / page / filter ──▶ setSearchParams ──▶ new URL ──▶ new query key ──▶ refetch
```

- **The URL is the source of truth for lists.** Reload, back/forward and shared links all restore the same view. The defaults (page 1, size 25, the endpoint's default sort) are left out of the URL.
- **The query key holds every input of the request**, so each page/filter combination is cached separately, and `placeholderData: keepPreviousData` keeps the old page visible (dimmed) while the next one loads.
- **Writes** go through `useApiMutation` hooks in `features/<module>/api.ts`. They invalidate `keys.all` for the resource on success.

### API client rules (`src/api/client.ts`)

| Situation | Behaviour |
|-----------|-----------|
| Every request | `credentials: 'include'`, `Accept: application/json`, JSON body |
| Non-GET | Ensures the `XSRF-TOKEN` cookie exists (fetches `/sanctum/csrf-cookie` once, shared by concurrent callers), sends it URL-decoded as `X-XSRF-TOKEN` |
| Login | Always refreshes the CSRF cookie first |
| 419 | Refreshes the CSRF cookie and retries the request **once** |
| Any non-2xx | Rejects with `ApiError { status, message, code?, errors, retryAfter? }`. `status: 0` = network failure |
| 401 | Calls `onUnauthorized` listeners (except calls with `skipUnauthorizedHandler`, used by `/auth/me` and login). `App.tsx` resets the session cache and navigates to `/login?redirect=<current URL>` |
| 202 | `api.send()` resolves to `{ kind: 'queued', approval }`, and `useApiMutation` toasts "Sent for approval" instead of the success message |

## 4. Auth and permissions

- **Session = the `/auth/me` query** (`features/auth/api.ts`). `AuthProvider` reads it and exposes `user`, `isAuthenticated`, `isLoading` and the permission checker. A 401 from `/me` resolves to `null` (signed out) rather than an error.
- `resetSession(queryClient, me)` is the only way the session changes (login, logout, global 401). It drops every other cached query, so data never leaks between users, and updates `/me` in place, so the mounted provider stays subscribed.
- **Permissions** are typed (`Permission` in `lib/permissions.ts`, mirrored from `App\Support\PermissionMatrix`; a smoke check confirmed all 71 match the API). Use `can('leads.create')` or `canAny(VIEW_ANY.leads)`. The scope tiers (`view-all` / `view-team` / `view-own`) are grouped in `VIEW_ANY` so "can see this module" reads as one requirement.
- **UI gating:** `<Can permission=... | anyOf=... | allOf=...>` for elements; `RequirePermission` (→ `/403`) for routes; `RequireAuth` (→ `/login?redirect=`) for the shell; `RedirectIfAuthenticated` on `/login` (follows `?redirect=`, but only to same-app paths).
- **The sidebar and the routes use the same permission lists** (`navigation.ts`, `router.tsx`), so a visible link never leads to a 403. The API re-checks everything anyway; the UI only hides what would fail.
- **Login page:** RHF + zod; 422 → inline error on `login`; 429 → a live "try again in N seconds" countdown that disables the button; 403 `account_inactive` / `ip_not_allowed` → the server's message. Demo quick-login buttons render only when `VITE_DEMO_MODE=true`.

## 5. Routing

`src/app/router.tsx` defines the tree. Each page is lazily imported (its own JS chunk), wrapped in its permission guard, and has its own `ErrorBoundary` (`RouteErrorBoundary`), so a crash in one page keeps the shell alive and offers a retry. After a deploy, a missing chunk is detected and the boundary offers a reload. `handle.crumb` feeds the breadcrumb and the document title. `AppLayout` shows a thin progress bar while a lazy chunk loads and moves focus to `<main>` after each navigation, so screen readers announce the new page.

| Path | Page | Guard |
|------|------|-------|
| `/login` | LoginPage | signed-out only |
| `/` | Dashboard: KPIs and charts from `GET /analytics/overview` (Phase 5) | `dashboard.view` |
| `/leads` | **Leads list (reference implementation)** with a Table / Board toggle (`?view=`) | any `leads.view-*` |
| `/imports` | CSV import wizard (leads, clients) | `leads.import` or `clients.import` |
| `/profile` | Profile, change password, two-factor and browser sessions | signed in |
| `/clients`, `/orders`, `/payments`, `/platform-accounts`, `/social-accounts`, `/approvals`, `/notifications`, `/users`, `/teams`, `/workstations`, `/services`, `/audit-log` | Feature screens (Phase 4; placeholders were replaced) | matching view permission |
| `/403`, `*` | Forbidden, NotFound | signed in |

### 5.1 Phase 5 features

- **Dashboard** (`features/dashboard`). `useOverview` calls `GET /analytics/overview`. The range (`?range=7d|30d|90d|12m`, default 30d) and the team filter (`?team=`, only for users with `reports.view-all`) live in the URL, so a reload or shared link keeps them. Charts use Recharts through the shadcn wrapper `components/ui/chart.tsx` (theme tokens, light and dark) and the `--chart-*` theme tokens (so both themes work). Each chart has an accessible summary: the chart is `role="img"` with an `aria-label` and a `sr-only` text equivalent, and loading, error and empty states are handled per panel (`ChartCard`). Panels: KPI cards, revenue series, funnel, leaderboard, account health, upcoming payments.
- **Leads board** (`features/leads/board`). The Leads page toggles Table and Board (`?view=`, last choice remembered in `localStorage`). The board has one infinite query per stage column (`board-api.ts`) and drag-and-drop with dnd-kit. Moves are optimistic through `PATCH /leads/{id}/stage`: a 200 keeps the move, a 202 (queued for approval) or any error puts the card back, and a queued card shows a pending badge. Moving to Lost opens `LostReasonDialog`; moving to Won opens `WonOrderDialog` to pick the order. Keyboard users drag with the dnd-kit keyboard sensor (with live announcements) or use each card's "Move to…" menu; users without `leads.update` or `leads.request-change` get a read-only board.
- **Command palette** (`app/layouts/CommandMenu.tsx`). Ctrl+K / Cmd+K. Typing two or more characters queries `GET /search` (debounced) and lists clients, leads, orders and platform accounts above the screens list; cmdk filtering is off because the server filters.
- **Client notes and timeline** (`features/clients`). `ClientNotes` (add, pin, edit, delete, driven by `can_edit` / `can_delete` from the API) and `ClientTimeline` (paginated activity feed) are tabs on the Client 360 page; data hooks are in `notes-api.ts`.
- **Import and export** (`features/imports`). `/imports` is a stepper wizard: upload, map columns (suggested mapping from the API), preview (first 50 rows, validated by the server), run (starts the job and polls `GET /imports/{id}` while it is queued or processing), result (counts, the first errors and a download of the error CSV). `RecentImports` lists past imports. `ExportCsvButton` sits on the Leads and Clients lists, is shown only with `reports.export`, and downloads `GET /exports/{type}` with the current filters and sort (`download.ts`).
- **Two-factor sign-in and sessions** (`features/auth`, `features/profile`). When login answers `{two_factor: true}`, `LoginForm` swaps to `TwoFactorChallengeForm` (6-digit `OtpCodeField`, or a recovery code). The profile page has a Security section: `TwoFactorCard` (QR code and secret, confirm, recovery codes via `RecoveryCodesPanel`, disable), `SessionsCard` (browsers list, sign out others) and `ConfirmPasswordDialog` for actions that need the password. When `/me` says `demo_mode`, `DemoModeNotice` explains why password and two-factor changes are off.

## 6. Design system

- **Brand colour:** indigo-violet, `oklch(0.51 0.23 277)` (light) / `oklch(0.68 0.17 277)` (dark). Streamers associate violet with their platform, which suits a team selling branding to streamers, and the indigo lean keeps it calm enough for business software. White text on the primary colour passes WCAG AA. Neutrals carry a faint tint of the same hue.
- **Theme:** system / light / dark, chosen in the user menu and stored in `localStorage['saleshub-theme']`. An inline script in `index.html` applies the `dark` class before the first paint, so there is no flash.
- **Type:** Inter Variable, self-hosted via `@fontsource-variable/inter` (no CDN), with `cv11`/`ss01` features. Use the `tabular` utility for numbers in columns.
- **Status colours** are semantic tones (`lib/enums.ts`): success, warning, danger, info, brand, neutral, muted. `<StatusBadge kind="leadStage" value={lead.stage} />` maps any backend enum to a consistent colour. Add new enums there, not in components.
- **Accessibility:** skip-to-content link, visible focus rings, `aria-current` on the active nav item (from `NavLink`), `aria-sort` on sortable headers, labelled icon buttons, `role="alert"` for form-level errors, and reduced-motion support.

## 7. Performance

Route pages are split into their own chunks. Vendor code is split into long-lived chunks (`vendor-react`, `vendor-router`, `vendor-query`, `vendor-ui`, see `vite.config.ts`), and the app chunk itself is about 90 kB (27 kB gzip). Zod and the form code load only with the pages that use them. The React Query devtools load only in development.

## 8. Testing

- `npm test` runs Vitest in jsdom. `src/test/setup.ts` starts an MSW server with default handlers (`src/test/handlers.ts`); override per test with `server.use(...)`. Unhandled requests fail the test.
- `renderWithProviders(ui, { user, route })` renders with the real providers and a memory router, and seeds `/auth/me` with a fixture user (`makeUser(overrides, permissions)`). `renderRoutes(routes, ...)` renders a route tree.
- jsdom lacks a few browser APIs: `src/test/setup.ts` stubs `matchMedia`, `ResizeObserver`, `scrollIntoView`, pointer capture and `document.elementFromPoint` (needed by the OTP input), so tests of those components need no per-file stubs.
- Test behaviour through roles and labels (`getByRole('button', { name: 'Next page' })`), and assert URLs with `router.state.location`.
- Fixtures use fictional names and `@example.com` addresses only.

## 9. Conventions

- Strict TypeScript, function components, `@/` imports, named exports, and default exports only for lazy route pages.
- Prettier: no semicolons, single quotes, trailing commas, 100 columns, Tailwind class sorting.
- Never hand-edit `src/api/schema.d.ts`; run `npm run api:types` after the OpenAPI spec changes.
- Never edit `src/components/ui/*` for styling; change tokens in `index.css`. Re-add components with `npx shadcn@latest add <name>`.
- Money is integer cents in requests (`MoneyField`) and the API's `formatted` string in display (`MoneyText`). Dates are `YYYY-MM-DD` strings in forms (`DateField`).
