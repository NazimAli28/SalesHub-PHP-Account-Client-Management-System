import { defineConfig, devices } from '@playwright/test'

/**
 * Smoke test of the static browser demo (GitHub Pages build). `npm run e2e:demo` builds it with
 * `--mode demo`, then this config serves `dist` under the repository base path with `vite preview`.
 * No PHP or database: the API runs in the page (src/demo). Not part of `npm run e2e`.
 */
const PORT = process.env.E2E_DEMO_PORT ?? '4181'
const BASE_PATH = '/SalesHub-PHP-Account-Client-Management-System/'

export default defineConfig({
  testDir: './e2e',
  testMatch: /static-demo\.spec\.ts/,
  fullyParallel: false,
  workers: 1,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  reporter: [['list']],
  use: {
    baseURL: `http://localhost:${PORT}${BASE_PATH}`,
    locale: 'en-US',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'static-demo', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: `npx vite preview --mode demo --port ${PORT} --strictPort --host localhost`,
    url: `http://localhost:${PORT}${BASE_PATH}`,
    timeout: 60_000,
    reuseExistingServer: Boolean(process.env.E2E_REUSE_SERVER),
  },
})
