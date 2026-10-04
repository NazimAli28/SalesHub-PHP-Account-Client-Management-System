import { screen, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser, paginate, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import { makeClientRecord } from '@/features/orders/test-data'
import ClientsPage from './ClientsPage'

function useClientList() {
  const requests: URL[] = []
  server.use(
    http.get('*/api/clients', ({ request }) => {
      requests.push(new URL(request.url))
      return HttpResponse.json(paginate([makeClientRecord(1), makeClientRecord(2)], { total: 2 }))
    }),
    http.get('*/api/teams', () => HttpResponse.json(paginate([]))),
  )
  return requests
}

describe('ClientsPage', () => {
  it('requests the page described by the URL and links rows to the detail page', async () => {
    const requests = useClientList()

    renderWithProviders(<ClientsPage />, {
      route:
        '/clients?status=active&has_open_orders=true&created_from=2026-09-01&q=pixel&page=2&size=10',
      user: makeUser(),
    })

    const link = await screen.findByRole('link', { name: 'Streamer 1' })
    expect(link).toHaveAttribute('href', '/clients/1')
    expect(Object.fromEntries(requests.at(-1)!.searchParams)).toEqual({
      'page[number]': '2',
      'page[size]': '10',
      sort: '-created_at',
      'filter[search]': 'pixel',
      'filter[status]': 'active',
      'filter[has_open_orders]': 'true',
      'filter[created_from]': '2026-09-01',
      include: 'owner',
    })
  })

  it('writes a chosen filter to the URL', async () => {
    useClientList()
    const { user, router } = renderWithProviders(<ClientsPage />, {
      route: '/clients',
      user: makeUser(),
    })
    await screen.findByText('Streamer 1')

    await user.click(screen.getByRole('button', { name: 'Status' }))
    await user.click(await screen.findByRole('option', { name: /Nurturing/ }))

    expect(router.state.location.search).toBe('?status=nurturing')
  })

  it('hides create and the owner filter from users without those permissions', async () => {
    useClientList()
    renderWithProviders(<ClientsPage />, {
      route: '/clients',
      user: makeUser(
        { roles: ['sales_executive'] },
        SALES_EXECUTIVE_PERMISSIONS.filter((p) => p !== 'clients.create'),
      ),
    })
    await screen.findByText('Streamer 1')
    expect(screen.queryByRole('button', { name: 'New client' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Status' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Owner' })).not.toBeInTheDocument()
  })

  it('says "Sent for approval" when a deletion is queued (202)', async () => {
    useClientList()
    server.use(
      http.delete('*/api/clients/1', () =>
        HttpResponse.json(
          { data: { id: 9, status: { value: 'pending', label: 'Pending' } } },
          { status: 202 },
        ),
      ),
    )
    const { user } = renderWithProviders(<ClientsPage />, {
      route: '/clients',
      user: makeUser({ roles: ['sales_executive'] }, [
        ...SALES_EXECUTIVE_PERMISSIONS,
        'clients.request-change',
      ]),
    })

    await user.click(await screen.findByRole('button', { name: 'Actions for Streamer 1' }))
    await user.click(await screen.findByRole('menuitem', { name: 'Request deletion' }))
    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Send request' }))

    expect(await screen.findByText('Sent for approval')).toBeInTheDocument()
  })

  it('shows 422 errors from the create form next to the field', async () => {
    useClientList()
    server.use(
      http.post('*/api/clients', () =>
        HttpResponse.json(
          { message: 'Invalid', errors: { discord_username: ['That username is already taken.'] } },
          { status: 422 },
        ),
      ),
    )
    const { user } = renderWithProviders(<ClientsPage />, {
      route: '/clients',
      user: makeUser(),
    })

    await user.click(await screen.findByRole('button', { name: 'New client' }))
    await user.type(await screen.findByRole('textbox', { name: /^Discord username/ }), 'streamer1')
    await user.click(screen.getByRole('button', { name: 'Create client' }))

    expect(await screen.findByText('That username is already taken.')).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: /^Discord username/ })).toHaveAttribute(
      'aria-invalid',
      'true',
    )
  })
})
