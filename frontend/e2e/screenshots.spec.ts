import path from 'node:path'
import { expect, test, type Page } from '@playwright/test'
import { apiRequest } from './support/api.ts'
import { storageStatePath, type Role } from './support/users.ts'

/**
 * README screenshots. Not part of `npm run e2e`: run `npm run screenshots` (see
 * playwright.config.ts). Images land in docs/screenshots/.
 */
const OUT = path.resolve('..', 'docs', 'screenshots')
const DESKTOP = { width: 1440, height: 900 }

// The single-threaded PHP dev server can be slow; give pages time to load.
test.setTimeout(180_000)
test.use({ actionTimeout: 60_000 })

async function settle(page: Page) {
  await page.waitForLoadState('networkidle')
  await expect(page.getByRole('status', { name: /^Loading/ })).toHaveCount(0)
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 60_000 })
  await expect(page.locator('[data-slot="skeleton"]')).toHaveCount(0, { timeout: 60_000 })
  // Let chart animations finish.
  await page.waitForTimeout(1800)
}

async function shot(page: Page, name: string) {
  // Hide the dev-only TanStack Query button.
  await page.addStyleTag({ content: '[class*="tsqd-open-btn"] { display: none !important }' })
  await page.screenshot({ path: path.join(OUT, `${name}.png`), type: 'png' })
}

test.describe('desktop', () => {
  test.use({ viewport: DESKTOP, deviceScaleFactor: 1, storageState: storageStatePath('admin') })

  for (const scheme of ['light', 'dark'] as const) {
    test(`dashboard (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme })
      await page.goto('/')
      await settle(page)
      await shot(page, `dashboard-${scheme}`)
    })
  }

  test('leads board', async ({ page }) => {
    await page.goto('/leads')
    await page.getByRole('radio', { name: 'Board view' }).click()
    await expect(page.getByRole('radio', { name: 'Board view' })).toBeChecked()
    await settle(page)
    await shot(page, 'leads-board')
  })

  test('client 360', async ({ page }) => {
    await page.goto('/clients')
    await expect(page.getByRole('table').locator('tbody tr').first()).toBeVisible({
      timeout: 60_000,
    })
    // Pick the client with the most orders so the page is full.
    const { body } = await apiRequest(page, 'GET', '/clients?page[size]=60&include=orders')
    const clients = (body as { data: { id: number; orders?: unknown[] }[] }).data
    const best = [...clients].sort((x, y) => (y.orders?.length ?? 0) - (x.orders?.length ?? 0))[0]
    await page.goto(`/clients/${best.id}`)
    await expect(page.getByRole('tab', { name: /^Orders/ })).toBeVisible({ timeout: 60_000 })
    await settle(page)
    await shot(page, 'client-360')
  })

  test('approval detail with before and after', async ({ page }) => {
    await page.goto('/approvals')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 60_000 })
    // An approved update shows what it looked like before and what was applied.
    const { body } = await apiRequest(
      page,
      'GET',
      '/approvals?filter[status]=approved&filter[action]=update&page[size]=1',
    )
    const id = (body as { data: { id: number }[] }).data[0].id
    await page.goto(`/approvals/${id}`)
    await expect(page.getByRole('columnheader', { name: 'Before' })).toBeVisible({
      timeout: 60_000,
    })
    await settle(page)
    await shot(page, 'approval-diff')
  })

  test('import wizard', async ({ page }) => {
    const csv = [
      'name,email,discord_username',
      'Miles Okafor,miles.okafor@example.com,miles_okafor',
      'Sana Whitfield,sana.whitfield@example.com,sana_whit',
      'Theo Brandt,theo.brandt@example.com,theo_brandt',
      'Ines Calder,ines.calder@example.com,ines_calder',
      'Remy Halvorsen,not-an-email,remy_halv',
    ].join('\n')
    await page.goto('/imports')
    await page.getByRole('radio', { name: /Clients/ }).check()
    await page
      .getByLabel('CSV file')
      .setInputFiles({ name: 'clients.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) })
    await page.getByRole('button', { name: 'Upload and continue' }).click()
    await expect(page.getByText('Match your columns')).toBeVisible({ timeout: 60_000 })
    await page.getByRole('button', { name: 'Preview rows' }).click()
    await expect(page.getByRole('button', { name: 'Continue' })).toBeVisible({ timeout: 60_000 })
    await settle(page)
    await shot(page, 'import-wizard')
  })

  test('command palette', async ({ page }) => {
    await page.goto('/clients')
    await expect(page.getByRole('table').locator('tbody tr').first()).toBeVisible({
      timeout: 60_000,
    })
    const name = (
      await page
        .getByRole('table')
        .locator('tbody tr')
        .first()
        .getByRole('link')
        .first()
        .innerText()
    ).trim()
    await settle(page)
    await page.keyboard.press('Control+K')
    const dialog = page.getByRole('dialog', { name: 'Search' })
    await expect(dialog).toBeVisible({ timeout: 60_000 })
    await dialog.getByRole('combobox').fill(name.split(' ')[0])
    await expect(dialog.getByRole('option').first()).toBeVisible({ timeout: 60_000 })
    await page.waitForTimeout(500)
    await shot(page, 'command-palette')
  })
})

test.describe('signed out', () => {
  test.use({ viewport: DESKTOP, deviceScaleFactor: 1, storageState: { cookies: [], origins: [] } })

  test('login', async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 60_000 })
    await page.waitForTimeout(500)
    await shot(page, 'login')
  })
})

test.describe('mobile', () => {
  const role: Role = 'agent1'
  test.use({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    storageState: storageStatePath(role),
  })

  test('today', async ({ page }) => {
    await page.goto('/today')
    await settle(page)
    await shot(page, 'mobile-today')
  })
})
