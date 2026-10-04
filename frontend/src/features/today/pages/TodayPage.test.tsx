import { screen, within } from '@testing-library/react'
import { subDays } from 'date-fns'
import { http, HttpResponse } from 'msw'
import { toIsoDate } from '@/lib/format'
import { makeLead, makeUser, paginate } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import type { DuePayment } from '../api'
import TodayPage from './TodayPage'

const PERMISSIONS = [
  'orders.view-own',
  'leads.view-own',
  'approvals.view-own',
  'payments.request-change',
] as const

const today = toIsoDate(new Date())

function makePayment(id: number, due: string): DuePayment {
  return {
    id,
    order_id: id + 50,
    sequence: 1,
    amount: { amount_cents: 12000, currency: 'USD', formatted: '$120.00' },
    due_date: due,
    status: { value: 'scheduled', label: 'Scheduled' },
    is_overdue: due < today,
    pending_change: null,
    order: {
      id: id + 50,
      order_number: `ORD-${id}`,
      client: { id: 3, name: 'Streamer Three', discord_username: 'streamer3' },
    },
  }
}

function section(title: string): HTMLElement {
  return screen.getByText(title).closest('[data-slot="card"]') as HTMLElement
}

describe('TodayPage', () => {
  it('shows an empty state in every section', async () => {
    server.use(
      http.get('*/api/payments', () => HttpResponse.json(paginate([]))),
      http.get('*/api/leads', () => HttpResponse.json(paginate([]))),
    )
    renderWithProviders(<TodayPage />, {
      route: '/today',
      user: makeUser({ roles: ['sales_executive'] }, PERMISSIONS),
    })

    expect(await screen.findByText('No payments due')).toBeInTheDocument()
    expect(await screen.findByText('No follow-ups due')).toBeInTheDocument()
    // The default pending-count handler reports 0 own requests.
    expect(await screen.findByText('No requests waiting')).toBeInTheDocument()
  })

  it('lists due and overdue payments and leads, with counts', async () => {
    let paymentQuery: URL | undefined
    let leadQuery: URL | undefined
    server.use(
      http.get('*/api/payments', ({ request }) => {
        paymentQuery = new URL(request.url)
        return HttpResponse.json(
          paginate([makePayment(1, toIsoDate(subDays(new Date(), 3))), makePayment(2, today)]),
        )
      }),
      http.get('*/api/leads', ({ request }) => {
        leadQuery = new URL(request.url)
        // The server applies filter[open] and filter[follow_up_to]; return only what it would.
        return HttpResponse.json(
          paginate([makeLead(1, { next_follow_up_on: toIsoDate(subDays(new Date(), 1)) })]),
        )
      }),
      http.get('*/api/approvals/pending-count', () =>
        HttpResponse.json({ data: { reviewable: 0, own: 2 } }),
      ),
    )
    renderWithProviders(<TodayPage />, {
      route: '/today',
      user: makeUser({ roles: ['sales_executive'] }, PERMISSIONS),
    })

    const payments = section('Payments due today and overdue')
    expect(await within(payments).findByText('Order ORD-1')).toBeInTheDocument()
    expect(within(payments).getByText(/Overdue/)).toBeInTheDocument()
    expect(within(payments).getByText(/Due today/)).toBeInTheDocument()
    expect(within(payments).getByLabelText('2 items')).toBeInTheDocument()
    expect(paymentQuery!.searchParams.get('filter[status]')).toBe('scheduled')
    expect(paymentQuery!.searchParams.get('filter[due_to]')).toBe(today)

    // Due and overdue follow-ups are filtered on the server.
    const leads = section('Lead follow-ups due today and overdue')
    expect(await within(leads).findByText('Streamer 1')).toBeInTheDocument()
    expect(leadQuery!.searchParams.get('filter[open]')).toBe('1')
    expect(leadQuery!.searchParams.get('filter[follow_up_to]')).toBe(today)
    expect(leadQuery!.searchParams.get('sort')).toBe('next_follow_up_on')

    expect(await screen.findByText(/You have 2 requests waiting/)).toBeInTheDocument()
  })

  it('says "Sent for approval" when marking a payment paid is queued (202)', async () => {
    server.use(
      http.get('*/api/payments', () => HttpResponse.json(paginate([makePayment(1, today)]))),
      http.get('*/api/leads', () => HttpResponse.json(paginate([]))),
      http.post('*/api/payments/1/mark-paid', () =>
        HttpResponse.json({ data: { id: 9 } }, { status: 202 }),
      ),
    )
    const { user } = renderWithProviders(<TodayPage />, {
      route: '/today',
      user: makeUser({ roles: ['sales_executive'] }, PERMISSIONS),
    })

    await user.click(await screen.findByRole('button', { name: /Mark paid: Streamer Three/ }))
    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Mark paid' }))

    expect(await screen.findByText('Sent for approval')).toBeInTheDocument()
  })
})
