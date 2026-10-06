# Deployment

The public live demo is the static browser build described in [Free browser demo](#free-browser-demo-github-pages). The full stack ships as **one Docker image on one origin**: Laravel (served by FrankenPHP, a Caddy-based PHP server) answers `/api/*`, `/sanctum/*` and `/docs/api`, and serves the built React app for every other page. The default configuration is a free, self-resetting public demo (SQLite inside the container); a real deployment swaps in MySQL through environment variables.

## The image

Multi-stage `Dockerfile` at the repo root:

1. **frontend** (`node:24-slim`): `npm ci && npm run build`. Build arg `VITE_DEMO_MODE=true` shows the quick-login buttons (default `false`).
2. **base** (`dunglas/frankenphp:1-php8.4`): adds `pdo_sqlite pdo_mysql intl zip bcmath opcache` and `tini`.
3. **vendor**: `composer install --no-dev`, optimized classmap autoloader. `fakerphp/faker` is a runtime dependency because the demo seeders (and `demo:reset`) use it.
4. **runtime**: app + vendor, the SPA's static files in `public/`, `index.html` moved to `resources/spa/index.html`. Runs as `www-data` (non-root) on port `$PORT` (default 8080).

How requests are served:

- `docker/Caddyfile`: existing files in `public/` are served directly by the web server (`/assets/*` with `Cache-Control: public, max-age=31536000, immutable`); everything else goes to Laravel.
- `routes/web.php` has a catch-all GET route (`SpaController`) that returns `index.html` with `Cache-Control: no-cache`, **through Laravel**, so the strict HTML CSP from `SecurityHeaders` applies. It excludes `api/*`, `sanctum/*`, `docs/*` and `up`, returns 404 for paths that look like files, and skips the `web` middleware (no session or cookie for the static shell). Without a built SPA (local development) it shows a placeholder page. `SPA_INDEX` overrides the file location.
- `docker/entrypoint.sh` (run under `tini`): checks `APP_KEY`, creates the SQLite file, builds the config/route/event/view caches from the live environment, runs `migrate:fresh --seed` (demo mode) or `migrate` (otherwise), then supervises FrankenPHP and `php artisan schedule:work` (and `queue:work` when `RUN_QUEUE_WORKER=true`). If any process exits, the container stops (the platform restarts it); SIGTERM stops all of them cleanly.

Idle memory is roughly 80 to 130 MB, well inside Render's 512 MB.

## Free browser demo (GitHub Pages)

The public live demo is a static site: <https://nazimali28.github.io/SalesHub-PHP-Account-Client-Management-System/>. Every container host with a free tier now asks for a card, so the demo runs the real React SPA with its API answered **inside the browser**.

**How it works**

1. `php artisan demo:export-static` (backend, `AppConsoleCommandsExportStaticDemoCommand` + `AppSupportStaticDemoExporter`) seeds a throw-away SQLite file with the normal seeders and writes `frontend/src/demo/data/demo-data.json` through the real API Resources: users, teams, workstations, services, clients and notes, leads, orders and items, payments, platform and social accounts, approvals, notifications, activity entries (IPs masked), the permission matrix, the `/auth/me` body per demo user and dashboard snapshots from the real `OverviewReport` (every active user x 7d/30d/90d/12m, plus the team filter for admin and support). It never contains password hashes, two-factor data or credential values (a Pest test checks this). It refuses to run outside `local`/`testing` without `--force`.
2. `npm run build:demo` (frontend) builds with `.env.demo`: `VITE_STATIC_DEMO=true`, the quick-login buttons and the base path. `main.tsx` lazy-loads `src/demo/start.ts`, which loads the JSON, moves every date by the days since the export (so "today" is the visitor's today) and starts an MSW service worker that implements every endpoint the SPA calls: envelopes, pagination, filters and sorts (400 for unknown ones), 401/403/404/409/422 bodies, `visibleTo` row scoping, the approval rule (200 / 202 / 403) and the maker-checker engine with notifications and audit entries.
3. `.github/workflows/pages.yml` runs on every push to `main` (or by hand): `npm ci`, `npm run build:demo`, then uploads `frontend/dist` and deploys it to Pages. The build includes `mockServiceWorker.js` and a `404.html` copy of `index.html`, so deep links such as `/leads?view=board` load the app.

**Enable it once:** repository **Settings > Pages > Build and deployment > Source: GitHub Actions**, then run the workflow (Actions > Browser demo > Run workflow) or push to `main`.

**Limits**

- There is no server: data lives in the tab. Changes survive a refresh in the same tab (sessionStorage) and disappear when the tab closes; **Reset demo data** in the banner starts over. Other visitors never see your changes.
- Dashboard numbers come from the export-time snapshots (the nearest range preset); only "Approvals waiting" is counted live.
- Credential reveals return placeholders such as `demo-email-password-3` and are written to the in-browser audit log.
- CSV exports are built in the browser from the same filtered list. CSV **imports are switched off** (upload answers `403 demo_mode`); templates still download.
- Password changes, two-factor setup and "sign out other sessions" answer `403 demo_mode`, as on a `DEMO_MODE=true` server.

**After seeder or Resource changes**, regenerate and commit the data set, then check the demo:

```bash
cd backend && php artisan demo:export-static   # writes ../frontend/src/demo/data/demo-data.json (about 1 MB)
cd ../frontend && npm run e2e:demo              # builds the demo and runs e2e/static-demo.spec.ts
```

`npm run preview:demo` serves the build at <http://localhost:4180/SalesHub-PHP-Account-Client-Management-System/>.

## Deploy to Render (free)

1. Push the repo to GitHub (branch `v2` or `main`).
2. Render dashboard: **New > Blueprint**, select the repo. Render reads `render.yaml` (Docker runtime, free plan, health check `/up`).
3. Fill the variables marked `sync: false`:
   - `APP_URL`: the service URL, e.g. `https://saleshub-demo.onrender.com` (no trailing slash). The URL is only known once the service exists, so set it and redeploy.
   - `SANCTUM_STATEFUL_DOMAINS`: the same host without the scheme, e.g. `saleshub-demo.onrender.com`.
   - `FRONTEND_URL`: same value as `APP_URL`.
   - `APP_KEY`: optional for the demo. Empty means the entrypoint generates an ephemeral key on every boot and logs a warning (sessions end on restart, which the demo reset does anyway). To pin one, run `php artisan key:generate --show` locally. Required when `DEMO_MODE` is not `true`; the container refuses to start without it.
4. First boot takes about a minute (it migrates and seeds the demo data). Open the URL and use the demo buttons, or sign in as `admin` / `Demo@12345`.

## Environment variables

| Variable | Demo value | Notes |
|----------|-----------|-------|
| `APP_ENV`, `APP_DEBUG` | `production`, `false` | Set in the image. |
| `APP_KEY` | empty or `base64:...` | Empty is allowed only with `DEMO_MODE=true`. |
| `APP_URL`, `FRONTEND_URL` | public URL | Same origin. CORS has no localhost fallback in production. |
| `SANCTUM_STATEFUL_DOMAINS` | public host (`host[:port]`) | Requests from it use cookie auth. |
| `SESSION_DRIVER`, `SESSION_SECURE_COOKIE` | `database`, `true` | Use `false` only for plain-HTTP local runs. |
| `DEMO_MODE` | `true` | Reseeds on boot, hourly `demo:reset`, protects shared accounts. |
| `TRUSTED_PROXIES` | `*` on Render | See security notes. |
| `DB_CONNECTION`, `DB_DATABASE` | `sqlite`, `/app/storage/database/saleshub.sqlite` | Image defaults. |
| `QUEUE_CONNECTION` | `sync` | Imports run inline. Use `database` with `RUN_QUEUE_WORKER=true` otherwise. |
| `LOG_CHANNEL`, `MAIL_MAILER` | `stderr`, `log` | Logs go to the platform log stream; mail is only logged. |
| `PUBLIC_API_DOCS` | `true` | Serves `/docs/api` (endpoints only). |
| `PORT` | set by the platform | Default 8080. |
| `RUN_QUEUE_WORKER` | unset | `true` starts `queue:work` next to the web server. |

See also `backend/.env.production.example`.

## Demo behavior and limits

- **Reset:** `schedule:work` runs `demo:reset` hourly (only when `DEMO_MODE=true`). It rebuilds the database from the seeders and signs everyone out. The container also reseeds on every start, redeploy and wake-up.
- **Cold start:** Render's free plan sleeps after about 15 minutes without traffic. The first request after that waits for a full container boot, roughly 30 to 60 seconds.
- **Ephemeral disk:** nothing survives a restart. Treat the data as disposable.
- **Mail** is written to the log. **Imports** run synchronously, so keep CSVs small.
- **One instance:** SQLite means a single container; do not scale out.

## Run the image locally

```bash
docker build -t saleshub:demo --build-arg VITE_DEMO_MODE=true .
docker run --rm -p 8080:8080 \
  -e APP_URL=http://localhost:8080 -e FRONTEND_URL=http://localhost:8080 \
  -e SANCTUM_STATEFUL_DOMAINS=localhost:8080 -e SESSION_SECURE_COOKIE=false \
  -e DEMO_MODE=true saleshub:demo
```

Wait for `http://localhost:8080/up`, then open `http://localhost:8080`.

## A real deployment with MySQL

Build without the demo buttons (`VITE_DEMO_MODE=false`, the default) and run with:

```
DEMO_MODE=false
APP_KEY=base64:...                # required: generate once and keep it
DB_CONNECTION=mysql
DB_HOST=...  DB_PORT=3306  DB_DATABASE=saleshub  DB_USERNAME=...  DB_PASSWORD=...
QUEUE_CONNECTION=database
RUN_QUEUE_WORKER=true
MAIL_MAILER=smtp                  # plus MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS
```

The entrypoint then runs `migrate --force` (never `migrate:fresh`). Seed the roles and permissions with `php artisan db:seed --class=RolesAndPermissionsSeeder` and create your own first admin user; do not seed the demo accounts. Put the container behind TLS and set `TRUSTED_PROXIES` to your proxy's address range.

## Security notes

- **Trusted proxies.** `TRUSTED_PROXIES` decides whose `X-Forwarded-For` and `X-Forwarded-Proto` headers are believed; they drive the client IP (rate limits, audit log, IP allowlist) and HTTPS detection (Secure cookies, HSTS). `*` is acceptable on Render because its proxy is the only path to the container. It is **wrong** if the container's port is reachable directly: any client could then spoof its IP and sidestep the rate limits. Prefer the proxy's CIDR when known.
- **Secure cookies.** `SESSION_SECURE_COOKIE=true` needs HTTPS as seen by the browser; the app learns about it from `X-Forwarded-Proto`.
- **HSTS** is sent only for HTTPS requests outside the local environment; the CSP includes `upgrade-insecure-requests`.
- Demo accounts have public passwords. Never put real data in a demo deployment.
- The image runs as a non-root user, has no `.env` baked in, and receives every secret through the environment.
