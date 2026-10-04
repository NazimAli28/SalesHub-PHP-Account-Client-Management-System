import { screen, waitFor, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import type { Lead } from '@/api/types'
import { makeLead, makeUser, paginate, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import LeadsPage from '../pages/LeadsPage'

const NEW = { value: 'new', label: 'New' } as const
const QUOTED = { value: 'quoted', label: 'Quoted' } as const

function stagedLeads(): Lead[] {
  return [
    makeLead(1, { stage: NEW }),
    makeLead(2, { stage: NEW, next_follow_up_on: '2020-01-01' }),
    makeLead(3, { stage: QUOTED }),
  ]
}

/** Serves GET /api/leads filtered by `filter[stage]`, like the real endpoint. */
function useBoardApi(leads: Lead[]) {
  const requests: URL[] = []
  server.use(
    http.get('*/api/leads', ({ request }) => {
      const url = new URL(request.url)
      requests.push(url)
      const stage = url.searchParams.get('filter[stage]')
      const rows = leads.filter((lead) => lead.stage.value === stage)
      return HttpResponse.json(paginate(rows, { perPage: 25 }))
    }),
  )
  return requests
}

function column(name: string) {
  return screen.getByRole('region', { name: `${name} column` })
}

async function moveTo(
  user: ReturnType<typeof renderWithProviders>['user'],
  lead: string,
  to: string,
) {
  await user.click(await screen.findByRole('button', { name: `Move ${lead} to…` }))
  await user.click(await screen.findByRole('menuitem', { name: to }))
}

const sales = () => makeUser({ roles: ['sales_executive'] }, SALES_EXECUTIVE_PERMISSIONS)

afterEach(() => {
  window.localStorage.clear()
})

describe('Leads board', () => {
  it('renders one column per stage with counts and loads each column separately', async () => {
    const requests = useBoardApi(stagedLeads())
    renderWithProviders(<LeadsPage />, { route: '/leads?view=board', user: makeUser() })

    expect(await screen.findByText('Streamer 1')).toBeInTheDocument()
    const headings = screen
      .getAllByRole('region')
      .map((region) => region.getAttribute('aria-label'))
      .filter((label) => label?.endsWith(' column'))
    expect(headings).toEqual([
      'New column',
      'Engaged column',
      'Portfolio Shared column',
      'Quoted column',
      'Payment Pending column',
      'Won column',
      'Lost column',
    ])
    expect(within(column('New')).getByLabelText('2 leads')).toBeInTheDocument()
    expect(within(column('Quoted')).getByLabelText('1 leads')).toBeInTheDocument()
    expect(await within(column('Won')).findByText('No leads here')).toBeInTheDocument()
    expect(requests.map((url) => url.searchParams.get('filter[stage]')).sort()).toEqual([
      'engaged',
      'lost',
      'new',
      'payment_pending',
      'portfolio_shared',
      'quoted',
      'won',
    ])
    expect(requests[0]!.searchParams.get('page[size]')).toBe('25')
    expect(within(column('New')).getByText('(overdue)')).toBeInTheDocument()
  })

  it('remembers the view and offers the table/board switch', async () => {
    useBoardApi(stagedLeads())
    const { user } = renderWithProviders(<LeadsPage />, { route: '/leads', user: makeUser() })

    await user.click(await screen.findByRole('radio', { name: 'Board view' }))
    expect(await screen.findByRole('group', { name: 'Leads pipeline' })).toBeInTheDocument()
    expect(window.localStorage.getItem('saleshub.leads.view')).toBe('board')
  })

  it('moves a card optimistically and keeps it on 200', async () => {
    useBoardApi(stagedLeads())
    let release!: () => void
    const gate = new Promise<void>((resolve) => (release = resolve))
    let body: unknown
    server.use(
      http.patch('*/api/leads/1/stage', async ({ request }) => {
        body = await request.json()
        await gate
        return HttpResponse.json({ data: makeLead(1, { stage: QUOTED }) })
      }),
    )
    const { user } = renderWithProviders(<LeadsPage />, {
      route: '/leads?view=board',
      user: makeUser(),
    })

    await moveTo(user, 'Streamer 1', 'Quoted')

    // Moved before the server answered.
    await waitFor(() =>
      expect(within(column('Quoted')).getByText('Streamer 1')).toBeInTheDocument(),
    )
    expect(within(column('New')).queryByText('Streamer 1')).not.toBeInTheDocument()
    expect(within(column('Quoted')).getByLabelText('2 leads')).toBeInTheDocument()

    release()
    expect(await screen.findByText('Stage updated')).toBeInTheDocument()
    expect(body).toEqual({ stage: 'quoted' })
  })

  it('rolls back and marks the card pending when the move is queued (202)', async () => {
    let leads = stagedLeads()
    server.use(
      http.get('*/api/leads', ({ request }) => {
        const stage = new URL(request.url).searchParams.get('filter[stage]')
        return HttpResponse.json(paginate(leads.filter((lead) => lead.stage.value === stage)))
      }),
      http.patch('*/api/leads/1/stage', () => {
        leads = leads.map((lead) =>
          lead.id === 1
            ? {
                ...lead,
                pending_change: {
                  id: 9,
                  action: { value: 'update', label: 'Update' },
                  fields: ['stage'],
                  requested_by: { id: 1, name: 'Robin Vale', username: 'agent1' },
                  requested_at: '2026-10-05T10:00:00Z',
                },
              }
            : lead,
        )
        return HttpResponse.json({ data: { id: 9 } }, { status: 202 })
      }),
    )
    const { user } = renderWithProviders(<LeadsPage />, {
      route: '/leads?view=board',
      user: sales(),
    })

    await moveTo(user, 'Streamer 1', 'Quoted')

    expect(await screen.findByText('Sent for approval')).toBeInTheDocument()
    await waitFor(() =>
      expect(within(column('New')).getByText('Pending approval')).toBeInTheDocument(),
    )
    expect(within(column('Quoted')).queryByText('Streamer 1')).not.toBeInTheDocument()
    expect(within(column('New')).getByText('Streamer 1')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Move Streamer 1 to…' })).toBeDisabled()
  })

  it('rolls back and shows the API error when the move is rejected', async () => {
    useBoardApi(stagedLeads())
    server.use(
      http.patch('*/api/leads/1/stage', () =>
        HttpResponse.json(
          { message: 'The order id field is required.', errors: { order_id: ['Required.'] } },
          { status: 422 },
        ),
      ),
    )
    const { user } = renderWithProviders(<LeadsPage />, {
      route: '/leads?view=board',
      user: makeUser(),
    })

    await moveTo(user, 'Streamer 1', 'Engaged')

    expect(await screen.findByText('The order id field is required.')).toBeInTheDocument()
    await waitFor(() => expect(within(column('New')).getByText('Streamer 1')).toBeInTheDocument())
    expect(within(column('Engaged')).queryByText('Streamer 1')).not.toBeInTheDocument()
    expect(within(column('New')).getByLabelText('2 leads')).toBeInTheDocument()
  })

  it('asks for a lost reason before moving to Lost, and cancelling changes nothing', async () => {
    useBoardApi(stagedLeads())
    let body: unknown
    server.use(
      http.patch('*/api/leads/1/stage', async ({ request }) => {
        body = await request.json()
        return HttpResponse.json({ data: makeLead(1, { stage: { value: 'lost', label: 'Lost' } }) })
      }),
    )
    const { user } = renderWithProviders(<LeadsPage />, {
      route: '/leads?view=board',
      user: makeUser(),
    })

    await moveTo(user, 'Streamer 1', 'Lost')
    let dialog = await screen.findByRole('dialog', { name: 'Mark as lost' })
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(body).toBeUndefined()
    expect(within(column('New')).getByText('Streamer 1')).toBeInTheDocument()

    await moveTo(user, 'Streamer 1', 'Lost')
    dialog = await screen.findByRole('dialog', { name: 'Mark as lost' })
    await user.click(within(dialog).getByRole('button', { name: 'Mark as lost' }))
    expect(await within(dialog).findByText('A lost reason is required.')).toBeInTheDocument()
    expect(body).toBeUndefined()

    await user.selectOptions(within(dialog).getByLabelText('Lost reason'), 'price')
    await user.type(within(dialog).getByLabelText('Note (optional)'), 'Too expensive')
    await user.click(within(dialog).getByRole('button', { name: 'Mark as lost' }))

    expect(await screen.findByText('Stage updated')).toBeInTheDocument()
    expect(body).toEqual({ stage: 'lost', lost_reason: 'price', lost_note: 'Too expensive' })
  })

  describe('moving to Won', () => {
    const order = (id: number, number: string) => ({
      id,
      order_number: number,
      total: { amount_cents: 12000, currency: 'USD', formatted: '$120.00' },
      ordered_on: '2026-09-20',
    })

    it('asks for an order of the same client and sends order_id', async () => {
      useBoardApi(stagedLeads())
      let ordersUrl: URL | undefined
      let body: unknown
      server.use(
        http.get('*/api/orders', ({ request }) => {
          ordersUrl = new URL(request.url)
          return HttpResponse.json(paginate([order(7, 'ORD-0007'), order(8, 'ORD-0008')]))
        }),
        http.patch('*/api/leads/1/stage', async ({ request }) => {
          body = await request.json()
          return HttpResponse.json({ data: makeLead(1, { stage: { value: 'won', label: 'Won' } }) })
        }),
      )
      const { user } = renderWithProviders(<LeadsPage />, {
        route: '/leads?view=board',
        user: makeUser(),
      })

      await moveTo(user, 'Streamer 1', 'Won')
      const dialog = await screen.findByRole('dialog', { name: 'Mark as won' })
      expect(await within(dialog).findByText('ORD-0007')).toBeInTheDocument()
      expect(ordersUrl!.searchParams.get('filter[client]')).toBe('101')

      await user.click(within(dialog).getByRole('button', { name: 'Mark as won' }))
      expect(await within(dialog).findByText('Choose an order.')).toBeInTheDocument()
      expect(body).toBeUndefined()

      await user.click(within(dialog).getByRole('radio', { name: /ORD-0008/ }))
      await user.click(within(dialog).getByRole('button', { name: 'Mark as won' }))

      expect(await screen.findByText('Stage updated')).toBeInTheDocument()
      expect(body).toEqual({ stage: 'won', order_id: 8 })
    })

    it('cancelling does not move the card', async () => {
      useBoardApi(stagedLeads())
      server.use(
        http.get('*/api/orders', () => HttpResponse.json(paginate([order(7, 'ORD-0007')]))),
      )
      let called = false
      server.use(
        http.patch('*/api/leads/1/stage', () => {
          called = true
          return HttpResponse.json({ data: {} })
        }),
      )
      const { user } = renderWithProviders(<LeadsPage />, {
        route: '/leads?view=board',
        user: makeUser(),
      })

      await moveTo(user, 'Streamer 1', 'Won')
      const dialog = await screen.findByRole('dialog', { name: 'Mark as won' })
      await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
      expect(called).toBe(false)
      expect(within(column('New')).getByText('Streamer 1')).toBeInTheDocument()
    })

    it('explains that an order must exist first when the client has none', async () => {
      useBoardApi(stagedLeads())
      server.use(
        http.get('*/api/orders', () => HttpResponse.json(paginate<never>([], { total: 0 }))),
      )
      const { user } = renderWithProviders(<LeadsPage />, {
        route: '/leads?view=board',
        user: makeUser(),
      })

      await moveTo(user, 'Streamer 1', 'Won')
      const dialog = await screen.findByRole('dialog', { name: 'Mark as won' })
      expect(await within(dialog).findByText('No orders for this client yet')).toBeInTheDocument()
      expect(within(dialog).getByRole('link', { name: 'Go to orders' })).toHaveAttribute(
        'href',
        '/orders',
      )
      expect(within(dialog).getByRole('button', { name: 'Mark as won' })).toBeDisabled()
    })
  })

  it('is read-only without leads.update or leads.request-change', async () => {
    useBoardApi(stagedLeads())
    renderWithProviders(<LeadsPage />, {
      route: '/leads?view=board',
      user: makeUser(
        { roles: ['sales_executive'] },
        SALES_EXECUTIVE_PERMISSIONS.filter((permission) => permission !== 'leads.request-change'),
      ),
    })

    expect(await screen.findByText('Streamer 1')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Drag / })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Move .* to…$/ })).not.toBeInTheDocument()
    expect(screen.getByText(/view-only access/)).toBeInTheDocument()
  })

  it('shows drag handles for users who may move cards', async () => {
    useBoardApi(stagedLeads())
    renderWithProviders(<LeadsPage />, { route: '/leads?view=board', user: makeUser() })

    expect(await screen.findByRole('button', { name: 'Drag Streamer 1' })).toBeInTheDocument()
  })

  it('loads more cards for a column from the next page', async () => {
    const first = [makeLead(1, { stage: NEW })]
    const second = [makeLead(2, { stage: NEW })]
    server.use(
      http.get('*/api/leads', ({ request }) => {
        const url = new URL(request.url)
        if (url.searchParams.get('filter[stage]') !== 'new') {
          return HttpResponse.json(paginate<Lead>([], { total: 0 }))
        }
        const page = Number(url.searchParams.get('page[number]'))
        const body = paginate(page === 1 ? first : second, { page, perPage: 1, total: 2 })
        body.meta.last_page = 2
        return HttpResponse.json(body)
      }),
    )
    const { user } = renderWithProviders(<LeadsPage />, {
      route: '/leads?view=board',
      user: makeUser(),
    })

    expect(await screen.findByText('Streamer 1')).toBeInTheDocument()
    expect(within(column('New')).getByLabelText('2 leads')).toBeInTheDocument()
    await user.click(within(column('New')).getByRole('button', { name: 'Load more' }))
    expect(await screen.findByText('Streamer 2')).toBeInTheDocument()
  })
})
