import { screen, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeClientDetail } from '@/features/orders/test-data'
import { makeUser, paginate, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import ClientDetailPage from './ClientDetailPage'

function useClient(detail = makeClientDetail(5)) {
  server.use(
    http.get('*/api/clients/5', () => HttpResponse.json({ data: detail })),
    http.get('*/api/clients/5/notes', () => HttpResponse.json(paginate([]))),
    http.get('*/api/clients/5/timeline', () => HttpResponse.json(paginate([]))),
  )
}

const render = (user = makeUser()) =>
  renderWithProviders(<ClientDetailPage />, {
    route: '/clients/5',
    path: '/clients/:clientId',
    user,
  })

describe('ClientDetailPage (Client 360)', () => {
  it('renders the header, KPI cards and tabs', async () => {
    useClient()
    const { user } = render()

    expect(await screen.findByRole('heading', { name: /Streamer 5/ })).toBeInTheDocument()
    expect(screen.getByText('@streamer5 · streamer5@example.com')).toBeInTheDocument()
    // Lifetime value from the profile card.
    expect(screen.getByText('$1,200.00')).toBeInTheDocument()

    // KPI cards: open balance sums the orders' balances, overdue comes from the counts.
    const balance = screen.getByText('Open balance').parentElement!
    expect(within(balance).getByText('$750.00')).toBeInTheDocument()
    const overdue = screen.getByText('Overdue payments').parentElement!
    expect(within(overdue).getByText('2')).toBeInTheDocument()
    expect(within(screen.getByText('Orders').parentElement!).getByText('1')).toBeInTheDocument()
    expect(within(screen.getByText('Leads').parentElement!).getByText('1')).toBeInTheDocument()

    // Orders tab is open first, with total / paid / balance.
    expect(screen.getByRole('tab', { name: 'Orders (1)' })).toHaveAttribute('aria-selected', 'true')
    const orders = screen.getByRole('table', { name: 'Client orders' })
    expect(within(orders).getByRole('link', { name: 'ORD-0007' })).toHaveAttribute(
      'href',
      '/orders/7',
    )
    expect(within(orders).getByText('$250.00')).toBeInTheDocument()

    // Payments tab: overdue highlighted, upcoming listed separately.
    await user.click(screen.getByRole('tab', { name: 'Payments (2)' }))
    const overdueTable = await screen.findByRole('table', { name: 'Overdue payments' })
    expect(overdueTable.querySelector('[data-overdue]')).not.toBeNull()
    expect(screen.getByRole('table', { name: 'Upcoming payments' })).toBeInTheDocument()

    // Leads tab: stage badge.
    await user.click(screen.getByRole('tab', { name: 'Leads (1)' }))
    expect(await screen.findByText('Quoted')).toBeInTheDocument()

    // Notes tab.
    await user.click(screen.getByRole('tab', { name: 'Notes' }))
    expect(await screen.findByText('Prefers pastel colours.')).toBeInTheDocument()
  })

  it('shows every action to an admin', async () => {
    useClient()
    render()
    await screen.findByRole('heading', { name: /Streamer 5/ })
    expect(screen.getByRole('button', { name: 'New order' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Delete' })).toBeInTheDocument()
  })

  it('hides edit and delete from a sales executive without those permissions', async () => {
    useClient()
    render(makeUser({ roles: ['sales_executive'] }, SALES_EXECUTIVE_PERMISSIONS))
    await screen.findByRole('heading', { name: /Streamer 5/ })
    expect(screen.getByRole('button', { name: 'New order' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Edit|Request change/ })).not.toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: /Delete|Request deletion/ }),
    ).not.toBeInTheDocument()
  })

  it('explains a 403 instead of showing a raw error', async () => {
    server.use(
      http.get('*/api/clients/5', () =>
        HttpResponse.json({ message: 'This action is unauthorized.' }, { status: 403 }),
      ),
    )
    render()
    expect(await screen.findByText("You don't have access to this record")).toBeInTheDocument()
  })
})
