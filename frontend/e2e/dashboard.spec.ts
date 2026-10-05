import { expect, test } from '@playwright/test'
import { dashboardHeading } from './support/helpers.ts'
import { storageStatePath, type Role } from './support/users.ts'

for (const role of ['admin', 'agent1'] as Role[]) {
  test.describe(`as ${role}`, () => {
    test.use({ storageState: storageStatePath(role) })

    test('the dashboard shows KPI cards and chart summaries', async ({ page }) => {
      await page.goto('/')
      await expect(dashboardHeading(page)).toBeVisible()

      const kpis = page.getByRole('region', { name: 'Key figures' })
      await expect(kpis).toBeVisible()
      await expect(kpis.getByText('Revenue collected')).toBeVisible()
      await expect(kpis.getByText('New leads')).toBeVisible()

      // Each chart exposes a text summary to assistive technology.
      const charts = page.getByRole('img', { name: /.{20,}/ })
      await expect(charts.first()).toBeVisible()
      await expect.poll(() => charts.count()).toBeGreaterThanOrEqual(2)
      for (const label of await charts.evaluateAll((nodes) =>
        nodes.map((node) => node.getAttribute('aria-label') ?? ''),
      )) {
        expect(label.length).toBeGreaterThan(10)
      }
      await expect(page.getByText('Quick links')).toBeVisible()
    })
  })
}
