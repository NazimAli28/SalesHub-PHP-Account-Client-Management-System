import { screen, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeItem, makeOrder, makePayment, money } from '@/features/orders/test-data'
import { makeUser, paginate, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import OrderDetailPage from './OrderDetailPage'

const overduePayment = makePayment(1, {
  order: undefined,
  is_overdue: true,
  due_date: '2026-09-15',
  amount: money(40000),
})
const laterPayment = makePayment(2, {
  order: undefined,
  amount: money(30000),
  due_date: '2026-11-15',
})

function baseOrder() {
  return makeOrder(7, {
    overdue_payments_count: 1,
    payments: [overduePayment, laterPayment],
  })
}

function render(user = makeUser()) {
  return renderWithProviders(<OrderDetailPage />, {
    route: '/orders/7',
    path: '/orders/:orderId',
    user,
  })
}

/** Serves the order and counts how many times it was fetched. */
function serveOrder(order = baseOrder()) {
  const state = { fetches: 0 }
  server.use(
    http.get('*/api/orders/7', () => {
      state.fetches += 1
      return HttpResponse.json({ data: order })
    }),
  )
  return state
}

describe('OrderDetailPage', () => {
  it('renders the header, totals, items and a payment schedule with overdue rows highlighted', async () => {
    serveOrder()
    render()

    expect(await screen.findByRole('heading', { name: /ORD-0007/ })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Streamer 7' })).toHaveAttribute('href', '/clients/107')
    const items = screen.getByRole('table', { name: 'Order items' })
    expect(within(items).getByText('Emote pack 1')).toBeInTheDocument()
    expect(within(items).getByTestId('items-total')).toHaveTextContent('$1,000.00')

    const schedule = screen.getByRole('table', { name: 'Payment schedule' })
    const rows = within(schedule).getAllByRole('row').slice(1)
    expect(rows[0]).toHaveAttribute('data-overdue', 'true')
    expect(within(rows[0]!).getByText('Overdue')).toBeInTheDocument()
    expect(rows[1]).not.toHaveAttribute('data-overdue')
    // $400 + $300 scheduled of $1,000: $300 left to schedule.
    expect(screen.getByText(/of the total is not\s+scheduled yet/)).toHaveTextContent('$300.00')
  })

  it('updates the totals from the item response without refetching the order', async () => {
    const state = serveOrder()
    const newItem = makeItem(2, {
      quantity: 1,
      unit_price: money(25000),
      line_total: money(25000),
    })
    let body: Record<string, unknown> | undefined
    server.use(
      http.get('*/api/services', () =>
        HttpResponse.json(
          paginate([
            {
              id: 12,
              name: 'Emote pack 2',
              base_price: money(25000),
              category: { value: 'emotes', label: 'Emotes' },
            },
          ]),
        ),
      ),
      http.post('*/api/orders/7/items', async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>
        return HttpResponse.json(
          {
            data: {
              ...baseOrder(),
              items: [...baseOrder().items!, newItem],
              subtotal: money(125000),
              total: money(125000),
              balance: money(125000),
            },
          },
          { status: 201 },
        )
      }),
    )
    const { user } = render()

    await screen.findByRole('heading', { name: /ORD-0007/ })
    await user.click(screen.getByRole('button', { name: 'Add item' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('combobox', { name: /Service/ }))
    await user.click(await screen.findByRole('option', { name: /Emote pack 2/ }))
    expect(within(dialog).getByRole('textbox', { name: /Unit price/ })).toHaveValue('250.00')
    await user.click(within(dialog).getByRole('button', { name: 'Add item' }))

    expect(await screen.findByText('Item added')).toBeInTheDocument()
    const items = screen.getByRole('table', { name: 'Order items' })
    expect(await within(items).findByTestId('items-total')).toHaveTextContent('$1,250.00')
    expect(within(items).getByText('Emote pack 2')).toBeInTheDocument()
    expect(body).toEqual({
      service_id: 12,
      quantity: 1,
      unit_price_cents: 25000,
      description: null,
    })
    // The order came back in the POST response: no second GET.
    expect(state.fetches).toBe(1)
  }, 30_000)

  it('hides the item editor from users who cannot update orders', async () => {
    serveOrder()
    render(
      makeUser({ roles: ['sales_executive'] }, [...SALES_EXECUTIVE_PERMISSIONS, 'payments.create']),
    )

    await screen.findByRole('heading', { name: /ORD-0007/ })
    expect(screen.queryByRole('button', { name: 'Add item' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Edit Emote/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Remove Emote/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument()
    // Payments can still be added by anyone with payments.create.
    expect(screen.getByRole('button', { name: 'Add installment' })).toBeInTheDocument()
  })

  it('records a payment directly (200)', async () => {
    serveOrder()
    server.use(
      http.post('*/api/payments/1/mark-paid', () =>
        HttpResponse.json({
          data: makePayment(1, { status: { value: 'paid', label: 'Paid' } }),
        }),
      ),
    )
    const { user } = render()

    await user.click(await screen.findByRole('button', { name: 'Actions for installment 1' }))
    await user.click(await screen.findByRole('menuitem', { name: 'Mark paid' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Mark as paid' }))

    expect(await screen.findByText('Payment recorded')).toBeInTheDocument()
  })

  it('says "Sent for approval" when a sales executive marks a payment paid (202)', async () => {
    serveOrder()
    server.use(
      http.post('*/api/payments/1/mark-paid', () =>
        HttpResponse.json(
          { data: { id: 9, status: { value: 'pending', label: 'Pending' } } },
          { status: 202 },
        ),
      ),
    )
    const { user } = render(
      makeUser({ roles: ['sales_executive'] }, [
        ...SALES_EXECUTIVE_PERMISSIONS,
        'payments.request-change',
      ]),
    )

    await user.click(await screen.findByRole('button', { name: 'Actions for installment 1' }))
    await user.click(await screen.findByRole('menuitem', { name: 'Mark paid' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Send for approval' }))

    expect(await screen.findByText('Sent for approval')).toBeInTheDocument()
  })

  it('shows the API validation error when an installment does not fit the order total', async () => {
    serveOrder()
    server.use(
      http.post('*/api/orders/7/payments', () =>
        HttpResponse.json(
          {
            message: 'Invalid',
            errors: {
              amount_cents: ['The scheduled installments would exceed the order total.'],
            },
          },
          { status: 422 },
        ),
      ),
    )
    const { user } = render()

    await user.click(await screen.findByRole('button', { name: 'Add installment' }))
    const dialog = await screen.findByRole('dialog')
    // The amount defaults to what is left to schedule ($300.00).
    expect(within(dialog).getByRole('textbox', { name: /Amount/ })).toHaveValue('300.00')
    await user.click(within(dialog).getByRole('button', { name: /Due date/ }))
    await user.click(await screen.findByRole('button', { name: /15(st|nd|rd|th)/ }))
    await user.click(within(dialog).getByRole('button', { name: 'Add installment' }))

    const message = await within(dialog).findByText(
      'The scheduled installments would exceed the order total.',
    )
    expect(message).toBeInTheDocument()
    expect(within(dialog).getByRole('textbox', { name: /Amount/ })).toHaveAttribute(
      'aria-invalid',
      'true',
    )
  }, 30_000)
})
