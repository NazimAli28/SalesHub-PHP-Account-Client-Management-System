import { expect, test } from '@playwright/test'
import { dashboardHeading, mainNav } from './support/helpers.ts'
import { DEMO_PASSWORD, ROLES, type Role } from './support/users.ts'

// These specs start signed out and create their own sessions.
test.use({ storageState: { cookies: [], origins: [] } })

const NAV_EXPECTATIONS: Record<Role, { present: string[]; absent: string[] }> = {
  admin: {
    present: [
      'Dashboard',
      'Leads',
      'Clients',
      'Orders',
      'Approvals',
      'Users',
      'Teams',
      'Audit log',
    ],
    absent: [],
  },
  support: {
    present: ['Dashboard', 'Leads', 'Clients', 'Platform accounts', 'Users'],
    absent: [],
  },
  tl: {
    present: ['Dashboard', 'Leads', 'Clients', 'Approvals'],
    absent: ['Audit log'],
  },
  agent1: {
    present: ['Dashboard', 'Today', 'Leads', 'Clients'],
    absent: ['Users', 'Audit log'],
  },
}

for (const role of Object.keys(ROLES) as Role[]) {
  test(`demo quick-login as ${role} shows only permitted modules`, async ({ page }) => {
    await page.goto('/login')
    await page.getByRole('button', { name: `Sign in as ${ROLES[role].label}` }).click()
    await expect(dashboardHeading(page)).toBeVisible()
    await expect(page).toHaveURL(/\/$/)

    const nav = mainNav(page)
    for (const name of NAV_EXPECTATIONS[role].present) {
      await expect(nav.getByRole('link', { name, exact: true })).toBeVisible()
    }
    for (const name of NAV_EXPECTATIONS[role].absent) {
      await expect(nav.getByRole('link', { name, exact: true })).toHaveCount(0)
    }
  })
}

test('a wrong password shows an error and stays on the login page', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Username or email').fill('agent2')
  await page.getByLabel('Password', { exact: true }).fill('definitely-not-the-password')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()

  await expect(page.getByRole('alert')).toContainText(/credentials|incorrect|invalid/i)
  await expect(page).toHaveURL(/\/login/)
})

test('signing in with the form works and signing out returns to the login page', async ({
  page,
}) => {
  await page.goto('/login')
  await page.getByLabel('Username or email').fill('agent2')
  await page.getByLabel('Password', { exact: true }).fill(DEMO_PASSWORD)
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  await expect(dashboardHeading(page)).toBeVisible()

  await page.getByRole('button', { name: /Account menu for/ }).click()
  await page.getByRole('menuitem', { name: 'Sign out' }).click()
  await expect(page).toHaveURL(/\/login/)

  // The session is gone: a protected page sends us back to sign in.
  await page.goto('/leads')
  await expect(page).toHaveURL(/\/login/)
})

test('a sales executive opening /users sees the access denied page', async ({ page }) => {
  await page.goto('/login')
  await page.getByRole('button', { name: 'Sign in as Sales Executive' }).click()
  await expect(dashboardHeading(page)).toBeVisible()

  await page.goto('/users')
  await expect(
    page.getByRole('heading', { level: 1, name: "You don't have access to this page" }),
  ).toBeVisible()
  await expect(page.getByText('403', { exact: true })).toBeVisible()
})
