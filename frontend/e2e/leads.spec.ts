import { expect, test } from '@playwright/test'
import { openLeadsBoard, moveFirstBoardCard } from './support/leads.ts'
import { toast } from './support/helpers.ts'
import { storageStatePath } from './support/users.ts'

test.describe('as admin', () => {
  test.use({ storageState: storageStatePath('admin') })

  test('creates a lead through the sheet and finds it in the table', async ({ page }) => {
    const message = `E2E follow-up ${Date.now()}`
    await page.goto('/leads')
    await page.getByRole('button', { name: 'New lead' }).click()

    const sheet = page.getByRole('dialog', { name: 'New lead' })
    await sheet.getByRole('combobox', { name: /Client/ }).click()
    await page.getByRole('option').first().click()
    await sheet.getByLabel('Last message').fill(message)
    await sheet.getByRole('button', { name: 'Create lead' }).click()

    await expect(toast(page, /created|saved/i)).toBeVisible()
    await expect(sheet).toBeHidden()

    await page.getByPlaceholder('Search client, email or message…').fill(message)
    await expect(
      page
        .getByRole('table')
        .getByRole('row', { name: new RegExp(message.slice(0, 12)) })
        .or(page.getByRole('table').locator('tbody tr').first()),
    ).toBeVisible()
    await expect(page.getByRole('table').locator('tbody tr')).toHaveCount(1)
  })

  test('moves a board card with the "Move to…" menu', async ({ page }) => {
    await openLeadsBoard(page)
    await moveFirstBoardCard(page)
    await expect(toast(page, 'Stage updated')).toBeVisible()
  })
})

test.describe('as a sales executive', () => {
  test.use({ storageState: storageStatePath('agent1') })

  test('a stage move is sent for approval', async ({ page }) => {
    await openLeadsBoard(page)
    await moveFirstBoardCard(page)
    await expect(toast(page, 'Sent for approval')).toBeVisible()
    await expect(page.getByText('Pending approval').first()).toBeVisible()
  })
})
