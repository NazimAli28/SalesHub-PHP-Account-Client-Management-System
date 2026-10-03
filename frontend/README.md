# SalesHub web app (frontend)

React 19 + TypeScript single-page app for SalesHub v2, built with Vite and Tailwind CSS.

## Setup

```bash
npm install
npm run dev        # http://localhost:5173, proxies /api and /sanctum to the Laravel API on :8000
```

## Scripts

| Command                           | What it does                           |
| --------------------------------- | -------------------------------------- |
| `npm run lint`                    | oxlint                                 |
| `npm run format` / `format:check` | Prettier (with Tailwind class sorting) |
| `npm run typecheck`               | TypeScript, strict mode                |
| `npm test`                        | Vitest + Testing Library               |
| `npm run build`                   | Production build into `dist/`          |
