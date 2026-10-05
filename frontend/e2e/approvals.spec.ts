import { expect, test } from '@playwright/test'
import { apiRequest } from './support/api.ts'
import { moveFirstBoardCard, openLeadsBoard } from './support/leads.ts'
import { toast } from './support/helpers.ts'
import { storageStatePath } from './support/users.ts'

// agent1 sits on team "Unit 1 Alpha", whose team lead is `tl`, so tl is a legitimate reviewer.
test('a team lead approves a change requested by a sales executive', async ({ browser }) => {
  const agent = await browser.newContext({ storageState: storageStatePath('agent1') })
  const lead = await agent.newPage()
  await openLeadsBoard(lead)
  const { leadId, stage } = await moveFirstBoardCard(lead)
  await expect(lead.getByText('Sent for approval')).toBeVisible()

  // The requester sees the request, but cannot decide it (neither in the UI nor through the API).
  await lead.goto('/approvals')
  await lead.getByRole('tab', { name: 'My requests' }).click()
  await lead.getByRole('link', { name: `Update lead #${leadId}` }).click()
  await expect(
    lead.getByRole('heading', { level: 1, name: new RegExp(`Update lead #${leadId}`) }),
  ).toBeVisible()
  await expect(lead.getByRole('button', { name: 'Cancel request' })).toBeVisible()
  await expect(lead.getByRole('button', { name: 'Approve' })).toHaveCount(0)
  await expect(lead.getByRole('button', { name: 'Reject' })).toHaveCount(0)

  const approvalId = Number(lead.url().split('/').pop())
  const own = await apiRequest(lead, 'POST', `/approvals/${approvalId}/approve`)
  expect(own.status).toBe(403)
  await agent.close()

  const reviewer = await browser.newContext({ storageState: storageStatePath('tl') })
  const page = await reviewer.newPage()
  await page.goto('/approvals')
  await page.getByRole('link', { name: `Update lead #${leadId}` }).click()
  await expect(
    page.getByRole('heading', { level: 1, name: new RegExp(`Update lead #${leadId}`) }),
  ).toBeVisible()
  await page.getByRole('button', { name: 'Approve' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Approve' }).click()
  await expect(toast(page, 'Request approved')).toBeVisible()
  await expect(page.getByRole('heading', { level: 1 }).getByText('Approved')).toBeVisible()

  // The change was applied to the lead.
  const applied = await apiRequest(page, 'GET', `/leads/${leadId}`)
  expect(applied.status).toBe(200)
  expect((applied.body as { data: { stage: { label: string } } }).data.stage.label).toBe(stage)
  await reviewer.close()
})
