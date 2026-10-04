import { screen, waitFor, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser, paginate } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import type { Overview } from '../types'
import DashboardPage from './DashboardPage'

const money = (cents: number) => ({
  amount_cents: cents,
  currency: 'USD',
  formatted: `$${(cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2 })}`,
})

function makeOverview(overrides: Partial<Overview> = {}): Overview {
  return {
    range: {
      from: '2026-09-06',
      to: '2026-10-05',
      previous_from: '2026-08-07',
      previous_to: '2026-09-05',
      bucket: 'day',
      days: 30,
    },
    currency: 'USD',
    kpis: {
      revenue_collected: { value: money(150000), previous: money(100000), change_pct: 50 },
      won_value: { value: money(80000), previous: money(100000), change_pct: -20 },
      won_count: { value: 4, previous: 5, change_pct: -20 },
      new_leads: { value: 18, previous: 0, change_pct: null },
      conversion_rate: { value: 22.5, previous: 20, change_pct: 12.5 },
      average_order_value: { value: money(53333), previous: money(53333), change_pct: 0 },
      overdue_payments: { count: 3, amount: money(27000) },
      pending_approvals: { reviewable: 2, submitted: 1 },
      active_clients: 41,
    },
    funnel: [
      { stage: { value: 'new', label: 'New' }, count: 7 },
      { stage: { value: 'engaged', label: 'Engaged' }, count: 5 },
      { stage: { value: 'won', label: 'Won' }, count: 4 },
    ],
    revenue_series: [
      { date: '2026-09-06', collected_cents: 50000, won_cents: 0 },
      { date: '2026-09-07', collected_cents: 100000, won_cents: 80000 },
    ],
    leaderboard: [
      { user: { id: 7, name: 'Ayla Mercer' }, collected: money(90000), won_leads: 3 },
      { user: { id: 8, name: 'Jonas Reid' }, collected: money(60000), won_leads: 1 },
    ],
    account_health: [
      { standing: { value: 'active', label: 'Active' }, count: 8 },
      { standing: { value: 'limited', label: 'Limited' }, count: 2 },
    ],
    upcoming_payments: [
      {
        id: 1,
        order: { id: 31, order_number: 'ORD-2026-0031', client_name: 'Streamer Three' },
        amount: money(25000),
        due_date: '2026-10-10',
      },
    ],
    ...overrides,
  }
}

const SE_PERMISSIONS = ['dashboard.view', 'reports.view-own', 'leads.view-own'] as const

function stubOverview(data: Overview, seen?: (url: URL) => void) {
  server.use(
    http.get('*/api/analytics/overview', ({ request }) => {
      seen?.(new URL(request.url))
      return HttpResponse.json({ data })
    }),
  )
}

describe('DashboardPage', () => {
  beforeEach(() => {
    // The team filter (admin and support only) loads the team list.
    server.use(http.get('*/api/teams', () => HttpResponse.json(paginate([]))))
  })

  it('shows KPIs with accessible deltas', async () => {
    stubOverview(makeOverview())
    renderWithProviders(<DashboardPage />, {
      route: '/',
      user: makeUser({ roles: ['admin'] }),
    })

    const kpis = await screen.findByRole('region', { name: 'Key figures' })
    expect(within(kpis).getByText('$1,500.00')).toBeInTheDocument()
    expect(within(kpis).getByText('up 50% vs previous 30 days')).toBeInTheDocument()
    expect(within(kpis).getByText('down 20% vs previous 30 days')).toBeInTheDocument()
    expect(within(kpis).getByText('No data in previous 30 days')).toBeInTheDocument()
    expect(within(kpis).getByText('unchanged vs previous 30 days')).toBeInTheDocument()
    expect(kpis).toHaveTextContent('$270.00 outstanding')
    expect(within(kpis).getByText('41')).toBeInTheDocument()
  })

  it('describes each chart for assistive technology', async () => {
    stubOverview(makeOverview())
    renderWithProviders(<DashboardPage />, { route: '/', user: makeUser({ roles: ['admin'] }) })

    expect(
      await screen.findByRole('img', {
        name: /daily revenue: \$1,500\.00 collected and \$800\.00 won/i,
      }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('img', { name: /Lead funnel.*New 7, Engaged 5, Won 4/ }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('img', {
        name: /Platform account standing: Active 8, Limited 2\. 10 accounts/,
      }),
    ).toBeInTheDocument()
    expect(screen.getByRole('cell', { name: 'Ayla Mercer' })).toBeInTheDocument()
  })

  it('links upcoming payments to their order', async () => {
    stubOverview(makeOverview())
    renderWithProviders(<DashboardPage />, { route: '/', user: makeUser({ roles: ['admin'] }) })

    const link = await screen.findByRole('link', { name: /Streamer Three/ })
    expect(link).toHaveAttribute('href', '/orders/31')
    for (const approvals of screen.getAllByRole('link', { name: /Approvals/ })) {
      expect(approvals).toHaveAttribute('href', '/approvals')
    }
  })

  it('requests the range from the URL and updates it from the presets', async () => {
    const seen: URL[] = []
    stubOverview(makeOverview(), (url) => seen.push(url))
    const { user, router } = renderWithProviders(<DashboardPage />, {
      route: '/?range=7d',
      user: makeUser({ roles: ['sales_executive'] }, SE_PERMISSIONS),
    })

    await screen.findByRole('region', { name: 'Key figures' })
    const first = seen[0]!
    const days =
      (Date.parse(first.searchParams.get('to')!) - Date.parse(first.searchParams.get('from')!)) /
      86_400_000
    expect(days).toBe(6)
    expect(first.searchParams.has('team_id')).toBe(false)
    expect(screen.getAllByText(/vs previous 7 days/).length).toBeGreaterThan(0)

    await user.click(screen.getByRole('radio', { name: 'Last 90 days' }))
    await waitFor(() => expect(router.state.location.search).toBe('?range=90d'))
    await waitFor(() => expect(seen.length).toBeGreaterThan(1))
  })

  it('hides the team filter, leaderboard and account health for a sales executive', async () => {
    stubOverview(makeOverview({ leaderboard: null, account_health: null }))
    renderWithProviders(<DashboardPage />, {
      route: '/',
      user: makeUser({ roles: ['sales_executive'] }, SE_PERMISSIONS),
    })

    await screen.findByRole('region', { name: 'Key figures' })
    expect(screen.queryByRole('combobox', { name: 'Team' })).not.toBeInTheDocument()
    expect(screen.queryByText('Top agents')).not.toBeInTheDocument()
    expect(screen.queryByText('Account health')).not.toBeInTheDocument()
  })

  it('lets an admin filter by team', async () => {
    server.use(
      http.get('*/api/teams', () =>
        HttpResponse.json(
          paginate([
            { id: 4, name: 'Unit 4 Delta', floor: 2, shift: { value: 'day', label: 'Day' } },
          ] as never[]),
        ),
      ),
    )
    const seen: URL[] = []
    stubOverview(makeOverview(), (url) => seen.push(url))
    renderWithProviders(<DashboardPage />, {
      route: '/?team=4',
      user: makeUser({ roles: ['admin'] }),
    })

    await screen.findByRole('region', { name: 'Key figures' })
    expect(seen[0]!.searchParams.get('team_id')).toBe('4')
    expect(screen.getByRole('combobox', { name: 'Team' })).toBeInTheDocument()
  })

  it('shows empty states when there is no activity', async () => {
    stubOverview(
      makeOverview({
        funnel: [{ stage: { value: 'new', label: 'New' }, count: 0 }],
        revenue_series: [{ date: '2026-09-06', collected_cents: 0, won_cents: 0 }],
        leaderboard: [],
        account_health: [{ standing: { value: 'active', label: 'Active' }, count: 0 }],
        upcoming_payments: [],
      }),
    )
    renderWithProviders(<DashboardPage />, { route: '/', user: makeUser({ roles: ['admin'] }) })

    expect(await screen.findByText('No revenue in this period')).toBeInTheDocument()
    expect(screen.getByText('No new leads in this period')).toBeInTheDocument()
    expect(screen.getByText('No results yet')).toBeInTheDocument()
    expect(screen.getByText('No platform accounts')).toBeInTheDocument()
    expect(screen.getByText('No upcoming payments')).toBeInTheDocument()
  })

  it('shows a loading skeleton, then an error with retry', async () => {
    let calls = 0
    server.use(
      http.get('*/api/analytics/overview', () => {
        calls += 1
        return calls === 1
          ? HttpResponse.json({ message: 'Server exploded' }, { status: 500 })
          : HttpResponse.json({ data: makeOverview() })
      }),
    )
    const { user } = renderWithProviders(<DashboardPage />, {
      route: '/',
      user: makeUser({ roles: ['admin'] }),
    })

    expect(screen.getByRole('status', { name: 'Loading key figures' })).toBeInTheDocument()
    expect(await screen.findByText('Could not load the dashboard')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /try again/i }))
    expect(await screen.findByRole('region', { name: 'Key figures' })).toBeInTheDocument()
  })
})
