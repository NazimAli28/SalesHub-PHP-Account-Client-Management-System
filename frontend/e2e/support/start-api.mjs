// Starts the isolated API the e2e suite talks to: a throw-away SQLite file, freshly migrated and
// seeded with the demo data, served by PHP's built-in server on port 8001 (the dev API on 8000
// and its database are never touched). Playwright runs this via `webServer` and stops it after.
import { spawn, spawnSync } from 'node:child_process'
import { mkdirSync, rmSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const backend =
    process.env.E2E_BACKEND_DIR ??
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../backend')
const php = process.env.PHP_BINARY || 'php'
const port = process.env.E2E_API_PORT || '8001'
const database = path.join(backend, 'database', 'e2e.sqlite')
const frontendPort = process.env.E2E_WEB_PORT || '5174'

const env = {
    ...process.env,
    APP_NAME: 'SalesHub',
    APP_ENV: 'e2e',
    APP_KEY: 'base64:ZTJlLWtleS1mb3ItbG9jYWwtdGVzdHMtb25seS0hISE=',
    APP_DEBUG: 'false',
    APP_URL: `http://localhost:${port}`,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: database,
    BCRYPT_ROUNDS: '4',
    LOG_CHANNEL: 'stderr',
    LOG_LEVEL: 'error',
    SESSION_DRIVER: 'database',
    SESSION_ENCRYPT: 'true',
    CACHE_STORE: 'array',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    BROADCAST_CONNECTION: 'log',
    FRONTEND_URL: `http://localhost:${frontendPort}`,
    SANCTUM_STATEFUL_DOMAINS: `localhost:${frontendPort},127.0.0.1:${frontendPort},localhost:${port},127.0.0.1:${port}`,
    IP_ALLOWLIST_ENABLED: 'false',
    PUBLIC_API_DOCS: 'false',
    DEMO_MODE: 'false',
}

rmSync(database, { force: true })
mkdirSync(path.dirname(database), { recursive: true })
writeFileSync(database, '')

const migrate = spawnSync(
    php,
    ['artisan', 'migrate:fresh', '--seed', '--force', '--no-interaction'],
    {
        cwd: backend,
        env,
        stdio: 'inherit',
    },
)
if (migrate.status !== 0) process.exit(migrate.status ?? 1)

const router = path.join(
    backend,
    'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
)
const server = spawn(php, ['-S', `127.0.0.1:${port}`, router], {
    cwd: path.join(backend, 'public'),
    env,
    stdio: 'inherit',
})

const stop = () => server.kill()
process.on('SIGINT', stop)
process.on('SIGTERM', stop)
server.on('exit', (code) => process.exit(code ?? 0))
