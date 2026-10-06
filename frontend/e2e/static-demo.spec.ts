import { expect, test, type Page } from '@playwright/test'
import { dashboardHeading, toast } from './support/helpers.ts'
import { moveFirstBoardCard } from './support/leads.ts'

/**
 * The static browser demo (GitHub Pages): the real SPA with the API answered in the page.
 * Run with `npm run e2e:demo` (playwright.demo.config.ts). Data lives in the tab's sessionStorage,
 * so each test signs in through the quick-login buttons and stays in one page.
 */

/** Paths are relative to the base path in `baseURL`. */
const go = (page: Page, path = '') => page.goto(path.replace(/^\//, ''))

async function signIn(page: Page, role: string) {
  await go(page, 'login')
  await page.getByRole('button', { name: `Sign in as ${role}` }).click()
  await expect(dashboardHeading(page)).toBeVisible()
}

async function signOut(page: Page) {
  await page.getByRole('button', { name: /Account menu for/ }).click()
  await page.getByRole('menuitem', { name: 'Sign out' }).click()
  await expect(page).toHaveURL(/\/login/)
}

async function openBoard(page: Page) {
  await go(page, 'leads?view=board')
  await expect(page.getByRole('radio', { name: 'Board view' })).toBeChecked()
}

test('admin: dashboard, board move, deep link, search, notes, reveal, sign out', async ({
  page,
}) => {
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))

  await signIn(page, 'Admin')
  await expect(page.getByRole('complementary', { name: 'Demo mode' })).toContainText(
    'the API runs in your browser',
  )
  await expect(page.getByText('Revenue collected', { exact: true })).toBeVisible()
  await expect(page.getByRole('caption').filter({ hasText: 'Lead funnel' })).toBeVisible()

  await openBoard(page)
  await moveFirstBoardCard(page)
  await expect(toast(page, 'Stage updated')).toBeVisible()

  // A refresh on a deep link keeps the session and the page (GitHub Pages serves 404.html).
  await page.reload()
  await expect(page.getByRole('radio', { name: 'Board view' })).toBeChecked()

  // Ctrl+K finds a client; Client 360 takes a note.
  await go(page, 'clients')
  const link = page.getByRole('table').locator('tbody tr').first().getByRole('link').first()
  const name = (await link.innerText()).trim()
  await page.getByRole('heading', { level: 1, name: 'Clients' }).click()
  await page.keyboard.press('Control+K')
  const dialog = page.getByRole('dialog', { name: 'Search' })
  await dialog.getByRole('combobox').fill(name)
  await expect(dialog.getByRole('option', { name: new RegExp(name) }).first()).toBeVisible()
  await page.keyboard.press('Enter')
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()

  await page.getByRole('tab', { name: 'Notes' }).click()
  const note = `Browser demo note ${Date.now()}`
  await page.getByPlaceholder('Write a note about this client…').fill(note)
  await page.getByRole('button', { name: 'Add note' }).click()
  await expect(page.getByText(note).first()).toBeVisible()

  // Credentials are placeholders, and the reveal lands in the audit log.
  await go(page, 'platform-accounts')
  await page.getByRole('table').locator('tbody tr').first().getByRole('link').first().click()
  await page.getByRole('button', { name: 'Reveal credentials' }).click()
  await expect(page.getByRole('button', { name: 'Hide now' })).toBeVisible()
  await go(page, 'audit-log')
  await expect(page.getByText(/credentials_revealed/i).first()).toBeVisible()

  await signOut(page)
  await go(page, 'leads')
  await expect(page).toHaveURL(/\/login/)
  expect(errors).toEqual([])
})

test('sales executive change goes to approval; the team lead approves it', async ({ page }) => {
  await signIn(page, 'Sales Executive')
  // Own-scope dashboard: no team leaderboard for a single person.
  await expect(page.getByText('Revenue collected', { exact: true })).toBeVisible()
  await expect(page.getByText('Top agents')).toHaveCount(0)
  await openBoard(page)
  const { leadId } = await moveFirstBoardCard(page)
  await expect(toast(page, 'Sent for approval')).toBeVisible()
  await expect(page.getByText('Pending approval').first()).toBeVisible()

  await go(page, 'users')
  await expect(
    page.getByRole('heading', { level: 1, name: "You don't have access to this page" }),
  ).toBeVisible()
  await signOut(page)

  // Same tab, so the queued request is still there for the reviewer.
  await signIn(page, 'Team Lead')
  await go(page, 'approvals')
  await page.getByRole('link', { name: `Update lead #${leadId}` }).click()
  await page.getByRole('button', { name: 'Approve' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Approve' }).click()
  await expect(toast(page, 'Request approved')).toBeVisible()
  await expect(page.getByRole('heading', { level: 1 }).getByText('Approved')).toBeVisible()
})
