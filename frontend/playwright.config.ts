import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end + accessibility suite. It starts its own API (port 8001, throw-away SQLite file
 * seeded with the demo data) and its own Vite server (port 5174) so the dev servers and the dev
 * database on 8000/5173 stay untouched. `PHP_BINARY` selects the PHP executable (default `php`).
 */
const API_PORT = process.env.E2E_API_PORT ?? '8001'
const WEB_PORT = process.env.E2E_WEB_PORT ?? '5174'
const baseURL = `http://localhost:${WEB_PORT}`

// Worker processes don't see the CLI arguments, but they inherit the environment.
if (process.argv.includes('--project=screenshots')) process.env.SHOW_SCREENSHOTS_PROJECT = '1'

export default defineConfig({
  testDir: './e2e',
  // One shared database: tests run one at a time, in file order.
  fullyParallel: false,
  workers: 1,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL,
    // A fixed timezone keeps date assertions (relative dates, "today") identical on every machine.
    timezoneId: 'UTC',
    locale: 'en-US',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    {
      name: 'chromium',
      testIgnore: /auth\.setup\.ts/,
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
    },
    // README screenshots: only registered by `npm run screenshots` (never part of `npm run e2e`).
    ...(process.env.SHOW_SCREENSHOTS_PROJECT
      ? [
          {
            name: 'screenshots',
            testMatch: /screenshots.spec.ts/,
            use: { ...devices['Desktop Chrome'] },
            dependencies: ['setup'],
          },
        ]
      : []),
  ],
  webServer: [
    {
      command: 'node e2e/support/start-api.mjs',
      url: `http://127.0.0.1:${API_PORT}/up`,
      timeout: 240_000,
      reuseExistingServer: Boolean(process.env.E2E_REUSE_SERVER),
      stdout: 'ignore',
      stderr: process.env.E2E_VERBOSE ? 'pipe' : 'ignore',
    },
    {
      command: `npx vite --port ${WEB_PORT} --strictPort --host localhost`,
      url: baseURL,
      timeout: 120_000,
      reuseExistingServer: Boolean(process.env.E2E_REUSE_SERVER),
      env: {
        E2E_API_URL: `http://127.0.0.1:${API_PORT}`,
        VITE_DEMO_MODE: 'true',
      },
    },
  ],
})
