import { expect, test } from '@playwright/test'
import { storageStatePath } from './support/users.ts'

test.use({ storageState: storageStatePath('admin') })

test('Ctrl+K finds a seeded client and Enter opens it', async ({ page }) => {
  await page.goto('/clients')
  const link = page.getByRole('table').locator('tbody tr').first().getByRole('link').first()
  const name = (await link.innerText()).trim()
  const href = await link.getAttribute('href')

  await page.getByRole('heading', { level: 1, name: 'Clients' }).click()
  await page.keyboard.press('Control+K')
  const dialog = page.getByRole('dialog', { name: 'Search' })
  await expect(dialog).toBeVisible()

  await dialog.getByRole('combobox').fill(name)
  await expect(dialog.getByRole('option', { name: new RegExp(name) }).first()).toBeVisible()
  await page.keyboard.press('Enter')

  await expect(page).toHaveURL(new RegExp(`${href}$`))
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
})
