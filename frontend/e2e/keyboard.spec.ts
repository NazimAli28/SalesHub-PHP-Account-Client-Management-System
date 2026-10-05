import { expect, test } from '@playwright/test'
import { storageStatePath } from './support/users.ts'

test.use({ storageState: storageStatePath('admin') })

test('the skip link is the first tab stop and moves focus to the main content', async ({
  page,
}) => {
  await page.goto('/leads')
  await expect(page.getByRole('heading', { level: 1, name: 'Leads' })).toBeVisible()

  await page.keyboard.press('Tab')
  const skip = page.getByRole('link', { name: 'Skip to content' })
  await expect(skip).toBeFocused()
  await expect(skip).toBeVisible()

  await page.keyboard.press('Enter')
  await expect(page.getByRole('main')).toBeFocused()
})

test('the command palette works with the keyboard alone', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

  await page.keyboard.press('Control+K')
  const dialog = page.getByRole('dialog', { name: 'Search' })
  await expect(dialog).toBeVisible()
  await expect(dialog.getByRole('combobox')).toBeFocused()

  // Arrow keys move through the screens list, Enter opens the highlighted one.
  await page.keyboard.type('leads')
  await expect(dialog.getByRole('option', { name: /Leads/ }).first()).toBeVisible()
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('Enter')
  await expect(page).toHaveURL(/\/leads$/)
  await expect(dialog).toBeHidden()

  // Opened from the search button with the keyboard, Escape closes it and returns focus there.
  const button = page.getByRole('button', { name: /^Search/ })
  await expect(page.getByRole('heading', { level: 1, name: 'Leads' })).toBeVisible()
  await button.focus()
  await expect(button).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(dialog).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(dialog).toBeHidden()
  await expect(button).toBeFocused()
})

test('a dialog traps focus and returns it to the button that opened it', async ({ page }) => {
  await page.goto('/leads')
  const opener = page.getByRole('button', { name: 'New lead' })
  await opener.focus()
  await page.keyboard.press('Enter')

  const dialog = page.getByRole('dialog', { name: 'New lead' })
  await expect(dialog).toBeVisible()

  // Tab around the whole form (and then some): focus never leaves the dialog.
  for (let i = 0; i < 25; i += 1) {
    await page.keyboard.press('Tab')
    const inside = await dialog.evaluate((node) => node.contains(node.ownerDocument.activeElement))
    expect(inside, `focus escaped the dialog after ${i + 1} Tab presses`).toBe(true)
  }
  for (let i = 0; i < 25; i += 1) {
    await page.keyboard.press('Shift+Tab')
    const inside = await dialog.evaluate((node) => node.contains(node.ownerDocument.activeElement))
    expect(inside, `focus escaped the dialog after ${i + 1} Shift+Tab presses`).toBe(true)
  }

  await page.keyboard.press('Escape')
  await expect(dialog).toBeHidden()
  await expect(opener).toBeFocused()
})
