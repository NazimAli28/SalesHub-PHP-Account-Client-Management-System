import { expect, test } from '@playwright/test'
import { storageStatePath } from './support/users.ts'

test.use({ storageState: storageStatePath('admin') })

test('imports clients from a CSV through the wizard', async ({ page }) => {
  // A unique run id keeps the Discord usernames (which must be unique) fresh on every run.
  const run = Date.now().toString(36)
  const csv = [
    'name,email,discord_username',
    `Miles Okafor,miles.okafor@example.com,miles_${run}`,
    `Sana Whitfield,sana.whitfield@example.com,sana_${run}`,
    `Theo Brandt,theo.brandt@example.com,theo_${run}`,
  ].join('\n')

  await page.goto('/imports')
  await expect(page.getByRole('heading', { level: 1, name: /Import/ })).toBeVisible()

  await page.getByRole('radio', { name: /Clients/ }).check()
  await page
    .getByLabel('CSV file')
    .setInputFiles({ name: 'clients.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) })
  await expect(page.getByText('clients.csv')).toBeVisible()
  await page.getByRole('button', { name: 'Upload and continue' }).click()

  // Columns are matched by name; check the mapping and move on.
  await expect(page.getByText('Match your columns')).toBeVisible()
  await expect(page.getByLabel('discord_username')).toHaveValue('discord_username')
  await page.getByRole('button', { name: 'Preview rows' }).click()

  await page.getByRole('button', { name: 'Continue' }).click()

  await page.getByRole('button', { name: 'Start import' }).click()
  await expect(page.getByText('Import finished')).toBeVisible()
  await expect(page.getByTestId('created-count')).toHaveText('3')
  await expect(page.getByTestId('failed-count')).toHaveText('0')

  // The imported clients are searchable.
  await page.getByRole('link', { name: 'View clients' }).click()
  await page.getByPlaceholder(/Search name, email or Discord/).fill(`miles_${run}`)
  await expect(page.getByRole('table').locator('tbody tr')).toHaveCount(1)
})
