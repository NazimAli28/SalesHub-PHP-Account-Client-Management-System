import { screen, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeClientRecord, makeOrder, money } from '@/features/orders/test-data'
import { makeUser, paginate, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import OrdersPage from './OrdersPage'

function useOrderList() {
  const requests: URL[] = []
  server.use(
    http.get('*/api/orders', ({ request }) => {
      requests.push(new URL(request.url))
      return HttpResponse.json(
        paginate(
          [
            makeOrder(7, {
              overdue_payments_count: 1,
              amount_paid: money(25000),
              balance: money(75000),
            }),
            makeOrder(8),
          ],
          { total: 2 },
        ),
      )
    }),
    http.get('*/api/teams', () => HttpResponse.json(paginate([]))),
  )
  return requests
}

describe('OrdersPage', () => {
  it('requests the page described by the URL and renders totals', async () => {
    server.use(http.get('*/api/clients/7', () => HttpResponse.json({ data: makeClientRecord(7) })))
    const requests = useOrderList()

    renderWithProviders(<OrdersPage />, {
      route:
        '/orders?status=in_progress&owner=4&team=1&client=7&has_overdue=true&ordered_from=2026-09-01&q=ORD&page=2&size=10',
      user: makeUser(),
    })

    const table = await screen.findByRole('table', { name: 'Orders' })
    expect(await within(table).findByRole('link', { name: 'ORD-0007' })).toHaveAttribute(
      'href',
      '/orders/7',
    )
    expect(within(table).getByText('$750.00')).toBeInTheDocument()
    expect(within(table).getByText('Overdue')).toBeInTheDocument()
    expect(Object.fromEntries(requests.at(-1)!.searchParams)).toEqual({
      'page[number]': '2',
      'page[size]': '10',
      sort: '-ordered_on',
      'filter[search]': 'ORD',
      'filter[status]': 'in_progress',
      'filter[owner]': '4',
      'filter[team]': '1',
      'filter[client]': '7',
      'filter[has_overdue]': 'true',
      'filter[ordered_from]': '2026-09-01',
      include: 'client,owner',
    })
    // The client filter resolves the id from the URL to a name.
    expect(await screen.findByRole('button', { name: /Client\s*Streamer 7/ })).toBeInTheDocument()
  })

  it('writes a chosen filter to the URL', async () => {
    useOrderList()
    const { user, router } = renderWithProviders(<OrdersPage />, {
      route: '/orders',
      user: makeUser(),
    })
    await screen.findByRole('link', { name: 'ORD-0007' })

    await user.click(screen.getByRole('button', { name: 'Status' }))
    await user.click(await screen.findByRole('option', { name: /Delivered/ }))

    expect(router.state.location.search).toBe('?status=delivered')
  })

  it('hides "New order" from users without orders.create', async () => {
    useOrderList()
    renderWithProviders(<OrdersPage />, {
      route: '/orders',
      user: makeUser(
        { roles: ['sales_executive'] },
        SALES_EXECUTIVE_PERMISSIONS.filter((p) => p !== 'orders.create'),
      ),
    })
    await screen.findByRole('link', { name: 'ORD-0007' })
    expect(screen.queryByRole('button', { name: 'New order' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Owner' })).not.toBeInTheDocument()
  })

  it('computes the order total in cents from items and discount, and posts those cents', async () => {
    useOrderList()
    const services = [
      {
        id: 21,
        name: 'Emote pack',
        base_price: money(10000),
        category: { value: 'emotes', label: 'Emotes' },
      },
      {
        id: 22,
        name: 'Stream overlay',
        base_price: money(2000),
        category: { value: 'overlays', label: 'Overlays' },
      },
    ]
    server.use(
      http.get('*/api/clients', () => HttpResponse.json(paginate([makeClientRecord(3)]))),
      http.get('*/api/services', () => HttpResponse.json(paginate(services))),
    )
    let body: Record<string, unknown> | undefined
    server.use(
      http.post('*/api/orders', async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>
        return HttpResponse.json({ data: makeOrder(55) }, { status: 201 })
      }),
    )
    const { user, router } = renderWithProviders(<OrdersPage />, {
      route: '/orders',
      user: makeUser(),
    })

    await user.click(await screen.findByRole('button', { name: 'New order' }))
    const sheet = await screen.findByRole('dialog')

    // Client
    await user.click(within(sheet).getByRole('combobox', { name: /Client/ }))
    await user.click(await screen.findByRole('option', { name: /Streamer 3/ }))

    // First item: choosing a service pre-fills its base price ($100.00).
    await user.click(within(sheet).getByRole('combobox', { name: /Service/ }))
    await user.click(await screen.findByRole('option', { name: /Emote pack/ }))
    const price = within(sheet).getByRole('textbox', { name: /Unit price/ })
    expect(price).toHaveValue('100.00')
    // Then the user overrides the price to $12.50 and orders three: 3 x 1250 = 3750 cents.
    await user.clear(price)
    await user.type(price, '12.50')
    const quantity = within(sheet).getByRole('spinbutton', { name: /Qty/ })
    await user.clear(quantity)
    await user.type(quantity, '3')
    expect(within(sheet).getByTestId('order-total')).toHaveTextContent('$37.50')

    // Second item at its $20.00 base price.
    await user.click(within(sheet).getByRole('button', { name: 'Add item' }))
    const comboboxes = within(sheet).getAllByRole('combobox', { name: /Service/ })
    await user.click(comboboxes[1]!)
    await user.click(await screen.findByRole('option', { name: /Stream overlay/ }))
    expect(within(sheet).getByTestId('order-subtotal')).toHaveTextContent('$57.50')

    // $7.50 discount: 5750 - 750 = 5000 cents.
    await user.type(within(sheet).getByRole('textbox', { name: 'Discount' }), '7.50')
    expect(within(sheet).getByTestId('order-total')).toHaveTextContent('$50.00')

    await user.click(within(sheet).getByRole('button', { name: 'Create order' }))

    expect(await screen.findByText('Order created')).toBeInTheDocument()
    expect(body).toMatchObject({
      client_id: 3,
      discount_cents: 750,
      items: [
        { service_id: 21, quantity: 3, unit_price_cents: 1250 },
        { service_id: 22, quantity: 1, unit_price_cents: 2000 },
      ],
    })
    // The new order opens.
    expect(router.state.location.pathname).toBe('/orders/55')
  }, 30_000)

  it('refuses a discount larger than the subtotal', async () => {
    useOrderList()
    const { user } = renderWithProviders(<OrdersPage />, { route: '/orders', user: makeUser() })

    await user.click(await screen.findByRole('button', { name: 'New order' }))
    const sheet = await screen.findByRole('dialog')
    await user.type(within(sheet).getByRole('textbox', { name: /Unit price/ }), '10')
    await user.type(within(sheet).getByRole('textbox', { name: 'Discount' }), '25')
    await user.click(within(sheet).getByRole('button', { name: 'Create order' }))

    expect(
      await within(sheet).findByText('The discount cannot exceed the order subtotal.'),
    ).toBeInTheDocument()
  })
})
