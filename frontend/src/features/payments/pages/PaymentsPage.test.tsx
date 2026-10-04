import { screen, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makePayment } from '@/features/orders/test-data'
import { makeUser, paginate, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import PaymentsPage from './PaymentsPage'

function usePaymentList() {
  const requests: URL[] = []
  server.use(
    http.get('*/api/payments', ({ request }) => {
      requests.push(new URL(request.url))
      return HttpResponse.json(
        paginate(
          [
            makePayment(1, { is_overdue: true, due_date: '2026-09-15' }),
            makePayment(2, { sequence: 2 }),
          ],
          { total: 2 },
        ),
      )
    }),
    http.get('*/api/teams', () => HttpResponse.json(paginate([]))),
  )
  return requests
}

describe('PaymentsPage', () => {
  it('defaults to scheduled (due and overdue) installments and puts that in the URL', async () => {
    const requests = usePaymentList()
    const { router } = renderWithProviders(<PaymentsPage />, {
      route: '/payments',
      user: makeUser(),
    })

    expect(await screen.findAllByRole('link', { name: 'ORD-0007' })).toHaveLength(2)
    expect(router.state.location.search).toBe('?status=scheduled')
    // Only one request: the seed redirect happens before the first fetch.
    expect(requests).toHaveLength(1)
    expect(Object.fromEntries(requests[0]!.searchParams)).toEqual({
      'page[number]': '1',
      'page[size]': '25',
      sort: 'due_date',
      'filter[status]': 'scheduled',
      include: 'order.client',
    })
    expect(
      within(screen.getByRole('table', { name: 'Payments' })).getByText('Overdue'),
    ).toBeInTheDocument()
  })

  it('requests exactly what the URL describes and does not re-seed a cleared filter', async () => {
    const requests = usePaymentList()
    renderWithProviders(<PaymentsPage />, {
      route: '/payments?overdue=true&due_from=2026-09-01&due_to=2026-09-30&team=2&owner=4',
      user: makeUser(),
    })

    await screen.findAllByRole('link', { name: 'ORD-0007' })
    expect(Object.fromEntries(requests.at(-1)!.searchParams)).toMatchObject({
      'filter[overdue]': 'true',
      'filter[due_from]': '2026-09-01',
      'filter[due_to]': '2026-09-30',
      'filter[team]': '2',
      'filter[owner]': '4',
    })
    expect(requests.at(-1)!.searchParams.has('filter[status]')).toBe(false)
  })

  it('writes a chosen filter to the URL', async () => {
    usePaymentList()
    const { user, router } = renderWithProviders(<PaymentsPage />, {
      route: '/payments?status=scheduled',
      user: makeUser(),
    })
    await screen.findAllByRole('link', { name: 'ORD-0007' })

    await user.click(screen.getByRole('button', { name: 'Overdue' }))
    await user.click(await screen.findByRole('option', { name: 'Overdue only' }))

    expect(router.state.location.search).toBe('?status=scheduled&overdue=true')
  })

  it('records a payment directly (200)', async () => {
    usePaymentList()
    let body: Record<string, unknown> | undefined
    server.use(
      http.post('*/api/payments/1/mark-paid', async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>
        return HttpResponse.json({
          data: makePayment(1, { status: { value: 'paid', label: 'Paid' } }),
        })
      }),
    )
    const { user } = renderWithProviders(<PaymentsPage />, {
      route: '/payments?status=scheduled',
      user: makeUser(),
    })

    await user.click(
      await screen.findByRole('button', { name: 'Actions for installment 1 of ORD-0007' }),
    )
    await user.click(await screen.findByRole('menuitem', { name: 'Mark paid' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Reference'), 'PP-1001')
    await user.click(within(dialog).getByRole('button', { name: 'Mark as paid' }))

    expect(await screen.findByText('Payment recorded')).toBeInTheDocument()
    expect(body).toMatchObject({ reference: 'PP-1001' })
  })

  it('queues the payment for approval for a sales executive (202)', async () => {
    usePaymentList()
    server.use(
      http.post('*/api/payments/1/mark-paid', () =>
        HttpResponse.json(
          { data: { id: 9, status: { value: 'pending', label: 'Pending' } } },
          { status: 202 },
        ),
      ),
    )
    const { user } = renderWithProviders(<PaymentsPage />, {
      route: '/payments?status=scheduled',
      user: makeUser({ roles: ['sales_executive'] }, [
        ...SALES_EXECUTIVE_PERMISSIONS,
        'payments.request-change',
      ]),
    })

    await user.click(
      await screen.findByRole('button', { name: 'Actions for installment 1 of ORD-0007' }),
    )
    await user.click(await screen.findByRole('menuitem', { name: 'Mark paid' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Send for approval' }))

    expect(await screen.findByText('Sent for approval')).toBeInTheDocument()
  })

  it('hides the mark-paid action from users who cannot change payments', async () => {
    usePaymentList()
    const { user } = renderWithProviders(<PaymentsPage />, {
      route: '/payments?status=scheduled',
      user: makeUser({ roles: ['sales_executive'] }, SALES_EXECUTIVE_PERMISSIONS),
    })

    await user.click(
      await screen.findByRole('button', { name: 'Actions for installment 1 of ORD-0007' }),
    )
    expect(await screen.findByRole('menuitem', { name: 'Open order' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Mark paid' })).not.toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Edit' })).not.toBeInTheDocument()
    expect(
      screen.queryByRole('menuitem', { name: /Delete|Request deletion/ }),
    ).not.toBeInTheDocument()
  })
})
