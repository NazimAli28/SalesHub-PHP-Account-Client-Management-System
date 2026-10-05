import { expect, type Locator, type Page } from '@playwright/test'

/** The first-level heading shown on the dashboard ("Good morning, Morgan"). */
export const dashboardHeading = (page: Page): Locator =>
  page.getByRole('heading', { level: 1, name: /^Good (morning|afternoon|evening),/ })

export const mainNav = (page: Page): Locator =>
  page.getByRole('navigation', { name: 'Main navigation' })

/** Names of every link in the sidebar, in order. */
export async function navLinkNames(page: Page): Promise<string[]> {
  const links = mainNav(page).getByRole('link')
  await expect(links.first()).toBeVisible()
  return (await links.allInnerTexts()).map((text) => text.trim()).filter(Boolean)
}

/** A toast (sonner) with the given title. */
export const toast = (page: Page, title: string | RegExp): Locator =>
  page.locator('[data-sonner-toast]').filter({ hasText: title })

/** Rows of the first data table on the page (header row excluded). */
export const tableRows = (page: Page): Locator =>
  page.getByRole('table').first().locator('tbody tr')
