# SalesHub v2 (in development)

> 🚧 **This branch is a full rebuild in progress.** The finished, working version is **v1.0**: plain PHP + MySQL.
> Browse it on the [`v1` branch](../../tree/v1) or the [`v1.0` release tag](../../tree/v1.0).

SalesHub is a role-based sales operations platform. It covers account inventory, leads pipeline, client retention and payments, with a maker-checker approval workflow. Version 2 rebuilds it on a modern stack: a **Laravel REST API** plus a **React + TypeScript** single-page app.

## Tech stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13 · PHP 8.4 · Sanctum (SPA auth) · spatie/laravel-permission |
| Frontend | React 19 · TypeScript · Vite · Tailwind CSS |
| Database | MySQL 8 (SQLite for tests) |
| Quality | Pest · Larastan · Pint · Vitest · Testing Library · oxlint · Prettier |
| CI | GitHub Actions (lint, static analysis, tests, build, dependency audit) |

## Repository layout

```text
backend/    Laravel API
frontend/   React + TypeScript SPA
.github/    CI workflow
docker-compose.yml   MySQL + Mailpit for local development
```

## Local development

**Requirements:** PHP 8.3+, Composer 2, Node.js 20+, MySQL 8 (or Docker).

```bash
# Optional: start MySQL + Mailpit
docker compose up -d
```

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve          # http://localhost:8000
```

```bash
cd frontend
npm install
npm run dev                # http://localhost:5173 (proxies /api to :8000)
```

## Roadmap

- [x] **Phase 0:** monorepo setup, tooling, CI
- [ ] **Phase 1:** database schema, authentication, roles & permissions
- [ ] **Phase 2:** core REST API (accounts, clients, leads, orders & payments, approvals)
- [ ] **Phase 3:** frontend foundation (app shell, auth, data tables, design system)
- [ ] **Phase 4:** all v1 screens rebuilt
- [ ] **Phase 5:** analytics, Kanban pipeline, client 360, import wizard, notifications
- [ ] **Phase 6:** end-to-end tests, accessibility and security hardening
- [ ] **Phase 7:** live demo deployment and v2.0 release

## License

© Nazim Ali. **All rights reserved.** This repository is published as a portfolio showcase. You're welcome to browse the code and run it locally to evaluate it. Any other use (copying, modifying, redistributing, or commercial use) requires written permission. See [LICENSE](LICENSE).
