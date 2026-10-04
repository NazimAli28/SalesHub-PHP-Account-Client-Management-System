import { screen, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import type { ApprovalItem } from '../api'
import { makeApproval } from '../testing/fixtures'
import ApprovalDetailPage from './ApprovalDetailPage'

function serveApproval(approval: ApprovalItem) {
  server.use(http.get('*/api/approvals/7', () => HttpResponse.json({ data: approval })))
}

function renderDetail() {
  return renderWithProviders(<ApprovalDetailPage />, {
    route: '/approvals/7',
    path: '/approvals/:approvalId',
    user: makeUser(),
  })
}

describe('ApprovalDetailPage', () => {
  it('renders the before/after diff with humanized labels and highlights changes', async () => {
    serveApproval(makeApproval(7, { can: { review: true, cancel: false } }))
    renderDetail()

    const table = await screen.findByRole('table', { name: 'Proposed changes' })
    const rows = within(table).getAllByRole('row')
    const stage = rows.find((row) => within(row).queryByText('Stage'))!
    expect(stage).toHaveAttribute('data-changed', 'true')
    expect(within(stage).getByText('New')).toBeInTheDocument()
    expect(within(stage).getByText(/Quoted/)).toBeInTheDocument()

    const value = rows.find((row) => within(row).queryByText('Estimated value'))!
    expect(within(value).getByText('$250.00')).toBeInTheDocument()
    expect(within(value).getByText(/\$300\.00/)).toBeInTheDocument()

    const followUp = rows.find((row) => within(row).queryByText('Next follow up'))!
    expect(followUp).toHaveAttribute('data-changed', 'false')

    expect(screen.getByRole('link', { name: 'Open leads list' })).toBeInTheDocument()
    expect(screen.getByText('Submitted by Ayla Mercer')).toBeInTheDocument()
  })

  it('shows decision buttons according to the can block', async () => {
    serveApproval(makeApproval(7, { can: { review: false, cancel: true } }))
    renderDetail()

    expect(await screen.findByRole('button', { name: 'Cancel request' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument()
  })

  it('requires a comment to reject', async () => {
    serveApproval(makeApproval(7, { can: { review: true, cancel: false } }))
    let posted = 0
    server.use(
      http.post('*/api/approvals/7/reject', () => {
        posted += 1
        return HttpResponse.json({ data: makeApproval(7) })
      }),
    )
    const { user } = renderDetail()

    await user.click(await screen.findByRole('button', { name: 'Reject' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Reject' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(/Explain the rejection/)
    expect(within(dialog).getByLabelText(/Comment/)).toHaveAttribute('aria-invalid', 'true')
    expect(posted).toBe(0)
  })

  it('explains a 409 and refreshes the request', async () => {
    let loads = 0
    server.use(
      http.get('*/api/approvals/7', () => {
        loads += 1
        return HttpResponse.json({
          data:
            loads === 1
              ? makeApproval(7, { can: { review: true, cancel: false } })
              : makeApproval(7, {
                  status: { value: 'approved', label: 'Approved' },
                  reviewer: { id: 2, name: 'Sam Okafor', username: 'support' },
                  reviewed_at: '2026-10-04T08:00:00Z',
                  applied_at: '2026-10-04T08:00:00Z',
                  can: { review: false, cancel: false },
                }),
        })
      }),
      http.post('*/api/approvals/7/approve', () =>
        HttpResponse.json({ message: 'Already decided.' }, { status: 409 }),
      ),
    )
    const { user } = renderDetail()

    await user.click(await screen.findByRole('button', { name: 'Approve' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Approve' }))

    expect(await screen.findByText(/already decided, or the record changed/)).toBeInTheDocument()
    expect(await screen.findByText('Approved by Sam Okafor')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument()
  })

  it('shows a clear message when the request is out of scope (403)', async () => {
    server.use(
      http.get('*/api/approvals/7', () =>
        HttpResponse.json({ message: 'This action is unauthorized.' }, { status: 403 }),
      ),
    )
    renderDetail()
    expect(await screen.findByText("You don't have access to this request")).toBeInTheDocument()
  })
})
