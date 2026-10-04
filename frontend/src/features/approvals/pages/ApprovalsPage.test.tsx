import { screen, waitFor, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser, paginate, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import { makeApproval } from '../testing/fixtures'
import ApprovalsPage from './ApprovalsPage'

function captureApprovals() {
  const urls: URL[] = []
  server.use(
    http.get('*/api/approvals', ({ request }) => {
      urls.push(new URL(request.url))
      return HttpResponse.json(
        paginate([makeApproval(1), makeApproval(2, { approvable: { type: 'client', id: 9 } })]),
      )
    }),
  )
  return urls
}

describe('ApprovalsPage', () => {
  it('offers every tab to an admin and defaults to pending requests needing review', async () => {
    const urls = captureApprovals()
    renderWithProviders(<ApprovalsPage />, { route: '/approvals', user: makeUser() })

    expect(await screen.findByRole('tab', { name: 'Needs my review' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'My requests' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'All (history)' })).toBeInTheDocument()

    await waitFor(() => {
      const last = urls.at(-1)!
      expect(last.searchParams.get('filter[reviewable]')).toBe('true')
      expect(last.searchParams.get('filter[status]')).toBe('pending')
    })
    expect(await screen.findByText('Update lead #41')).toBeInTheDocument()
    expect(screen.getByText('Update client #9')).toBeInTheDocument()
  })

  it('shows only "My requests" to a sales executive and scopes the query to them', async () => {
    const urls = captureApprovals()
    renderWithProviders(<ApprovalsPage />, {
      route: '/approvals',
      user: makeUser({ id: 12, roles: ['sales_executive'] }, SALES_EXECUTIVE_PERMISSIONS),
    })

    expect(await screen.findByRole('tab', { name: 'My requests' })).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Needs my review' })).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'All (history)' })).not.toBeInTheDocument()
    await waitFor(() => {
      expect(urls.at(-1)!.searchParams.get('filter[requester]')).toBe('12')
    })
    // No review rights: no selection checkboxes.
    await screen.findByText('Update lead #41')
    expect(screen.queryByRole('checkbox', { name: 'Select row' })).not.toBeInTheDocument()
  })

  it('summarises a bulk approve with successes and failures', async () => {
    captureApprovals()
    server.use(
      http.post('*/api/approvals/1/approve', () =>
        HttpResponse.json({
          data: makeApproval(1, { status: { value: 'approved', label: 'Approved' } }),
        }),
      ),
      http.post('*/api/approvals/2/approve', () =>
        HttpResponse.json({ message: 'This request was already decided.' }, { status: 409 }),
      ),
    )
    const { user } = renderWithProviders(<ApprovalsPage />, {
      route: '/approvals',
      user: makeUser(),
    })

    await screen.findByText('Update lead #41')
    await user.click(screen.getByRole('checkbox', { name: 'Select all rows on this page' }))
    await user.click(await screen.findByRole('button', { name: 'Approve selected' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Approve' }))

    expect(await screen.findByText('Approved 1 of 2, 1 failed')).toBeInTheDocument()
    expect(screen.getByText(/already decided/)).toBeInTheDocument()
  })

  it('asks for one comment before a bulk reject', async () => {
    captureApprovals()
    const rejected: unknown[] = []
    server.use(
      http.post('*/api/approvals/:id/reject', async ({ request }) => {
        rejected.push(await request.json())
        return HttpResponse.json({ data: makeApproval(1) })
      }),
    )
    const { user } = renderWithProviders(<ApprovalsPage />, {
      route: '/approvals',
      user: makeUser(),
    })

    await screen.findByText('Update lead #41')
    await user.click(screen.getByRole('checkbox', { name: 'Select all rows on this page' }))
    await user.click(await screen.findByRole('button', { name: 'Reject selected' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Reject' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(/at least 5 characters/)
    expect(rejected).toHaveLength(0)

    await user.type(within(dialog).getByLabelText(/Comment/), 'Needs a clearer quote')
    await user.click(within(dialog).getByRole('button', { name: 'Reject' }))
    expect(await screen.findByText('Rejected 2 requests')).toBeInTheDocument()
    expect(rejected).toEqual([
      { comment: 'Needs a clearer quote' },
      { comment: 'Needs a clearer quote' },
    ])
  })
})
