import { AxeBuilder } from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { firstId } from './support/api.ts'
import { storageStatePath } from './support/users.ts'

type Scheme = 'light' | 'dark'
const SCHEMES: Scheme[] = ['light', 'dark']
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']

/** Waits until the page stopped loading data (skeletons and spinners gone, network quiet). */
async function settle(page: Page) {
  await page.waitForLoadState('networkidle')
  await expect(page.getByRole('status', { name: /^Loading/ })).toHaveCount(0)
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  // Charts animate in; contrast is only meaningful once they stopped moving.
  await page.waitForTimeout(1200)
}

/** Fails with a readable list of serious or critical WCAG A/AA violations. */
async function expectNoSeriousViolations(page: Page, name: string) {
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze()
  const serious = violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
  const lines = serious.flatMap((v) => [
    `[${v.impact}] ${v.id}: ${v.help}`,
    ...v.nodes.map((node) => {
      const data = node.any[0]?.data as
        { fgColor?: string; bgColor?: string; contrastRatio?: number } | undefined
      const colors = data?.contrastRatio
        ? ` (${data.fgColor} on ${data.bgColor}, ${data.contrastRatio}:1)`
        : ''
      return `    ${node.target.join(' ')}${colors}`
    }),
  ])
  // A plain number plus a message keeps Playwright from printing a huge object diff.
  expect(serious.length, [name, ...lines].join(String.fromCharCode(10))).toBe(0)
}

test.describe('signed out', () => {
  test.use({ storageState: { cookies: [], origins: [] } })

  for (const scheme of SCHEMES) {
    test(`login page has no serious violations (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme })
      await page.goto('/login')
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      await expectNoSeriousViolations(page, `/login (${scheme})`)
    })
  }
})

test.describe('as admin', () => {
  test.use({ storageState: storageStatePath('admin') })

  const staticScreens = [
    '/',
    '/today',
    '/leads',
    '/clients',
    '/orders',
    '/payments',
    '/platform-accounts',
    '/social-accounts',
    '/approvals',
    '/notifications',
    '/users',
    '/teams',
    '/workstations',
    '/services',
    '/audit-log',
    '/imports',
    '/profile',
  ]

  for (const scheme of SCHEMES) {
    test.describe(scheme, () => {
      test.beforeEach(async ({ page }) => {
        await page.emulateMedia({ colorScheme: scheme })
      })

      for (const path of staticScreens) {
        test(`${path} has no serious violations`, async ({ page }) => {
          await page.goto(path)
          await settle(page)
          await expectNoSeriousViolations(page, `${path} (${scheme})`)
        })
      }

      test('/leads board has no serious violations', async ({ page }) => {
        await page.goto('/leads')
        await page.getByRole('radio', { name: 'Board view' }).click()
        // Seven columns load one by one from the single-threaded test server.
        await expect(page.getByRole('button', { name: /^Move .* to…$/ }).first()).toBeVisible({
          timeout: 45_000,
        })
        await settle(page)
        await expectNoSeriousViolations(page, `/leads board (${scheme})`)
      })

      test('the new lead sheet has no serious violations', async ({ page }) => {
        await page.goto('/leads')
        await page.getByRole('button', { name: 'New lead' }).click()
        await expect(page.getByRole('dialog', { name: 'New lead' })).toBeVisible()
        await page.waitForTimeout(500)
        await expectNoSeriousViolations(page, `new lead sheet (${scheme})`)
      })

      test('the command palette has no serious violations', async ({ page }) => {
        await page.goto('/')
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        await page.keyboard.press('Control+K')
        await expect(page.getByRole('dialog', { name: 'Search' })).toBeVisible()
        await page.waitForTimeout(500)
        await expectNoSeriousViolations(page, `command palette (${scheme})`)
      })

      for (const [resource, route] of [
        ['clients', '/clients'],
        ['orders', '/orders'],
        ['platform-accounts', '/platform-accounts'],
        ['approvals', '/approvals'],
      ] as const) {
        test(`${route}/:id has no serious violations`, async ({ page }) => {
          await page.goto('/')
          const id = await firstId(page, resource)
          await page.goto(`${route}/${id}`)
          await settle(page)
          await expectNoSeriousViolations(page, `${route}/${id} (${scheme})`)
        })
      }
    })
  }
})
