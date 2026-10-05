import { expect, type Page } from '@playwright/test'

/** Opens the leads board (the choice is remembered per browser, so this is idempotent). */
export async function openLeadsBoard(page: Page) {
  await page.goto('/leads')
  await page.getByRole('radio', { name: 'Board view' }).click()
  await expect(page.getByRole('radio', { name: 'Board view' })).toBeChecked()
}

/**
 * Moves the first movable card (no change already waiting for approval) to "Engaged" or
 * "Portfolio Shared", whichever differs from its column. Returns the lead id from the request.
 */
export async function moveFirstBoardCard(page: Page): Promise<{ leadId: number; stage: string }> {
  const moveButtons = page.getByRole('button', { name: /^Move .* to…$/ })
  await expect(moveButtons.first()).toBeVisible()
  await moveButtons.locator('visible=true').and(page.locator(':enabled')).first().click()
  const item = page.getByRole('menuitem', { name: /^(Engaged|Portfolio Shared)$/ }).first()
  const stage = (await item.innerText()).trim()
  const request = page.waitForResponse(
    (response) =>
      /\/api\/leads\/\d+\/stage$/.test(response.url()) && response.request().method() === 'PATCH',
  )
  await item.click()
  const response = await request
  const leadId = Number(response.url().match(/leads\/(\d+)\/stage/)?.[1])
  return { leadId, stage }
}
