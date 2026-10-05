import { expect, test } from '@playwright/test'
import { storageStatePath } from './support/users.ts'

test.use({ storageState: storageStatePath('admin') })

test('adds a note on Client 360 and sees it in Notes and Activity', async ({ page }) => {
  const note = `Prefers evening calls, confirmed ${Date.now()}`

  await page.goto('/clients')
  const firstClient = page.getByRole('table').locator('tbody tr').first().getByRole('link').first()
  const name = (await firstClient.innerText()).trim()
  await firstClient.click()
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()

  await page.getByRole('tab', { name: 'Notes' }).click()
  await page.getByLabel('New note').fill(note)
  await page.getByRole('button', { name: 'Add note' }).click()
  await expect(page.getByRole('list', { name: 'Client notes' }).getByText(note)).toBeVisible()

  await page.getByRole('tab', { name: 'Activity' }).click()
  await expect(
    page.getByRole('list', { name: 'Client activity' }).getByText(/note/i).first(),
  ).toBeVisible()
})
