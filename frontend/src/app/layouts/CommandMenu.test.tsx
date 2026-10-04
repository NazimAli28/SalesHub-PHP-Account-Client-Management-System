import { screen, waitFor } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import { CommandMenu } from './CommandMenu'

const GROUPS = [
  {
    key: 'clients',
    label: 'Clients',
    hits: [
      {
        type: 'client',
        id: 12,
        title: 'Zelda Streamer',
        subtitle: 'zeldastream',
        url: '/clients/12',
      },
    ],
  },
  {
    key: 'orders',
    label: 'Orders',
    hits: [
      {
        type: 'order',
        id: 7,
        title: 'SH-2026-00007',
        subtitle: 'Zelda Streamer',
        url: '/orders/7',
      },
    ],
  },
]

function renderMenu() {
  return renderWithProviders(<CommandMenu />, {
    user: makeUser(),
    extraRoutes: [{ path: '/clients/:id', element: <p>Client page</p> }],
  })
}

describe('CommandMenu', () => {
  it('opens with Ctrl+K and lists screens without querying the API for short input', async () => {
    const requests: string[] = []
    server.use(
      http.get('*/api/search', ({ request }) => {
        requests.push(request.url)
        return HttpResponse.json({ data: [] })
      }),
    )
    const { user } = renderMenu()

    await user.keyboard('{Control>}k{/Control}')
    expect(await screen.findByRole('option', { name: /Leads/ })).toBeInTheDocument()

    await user.keyboard('z')
    await new Promise((resolve) => setTimeout(resolve, 400))
    expect(requests).toEqual([])
  })

  it('searches records after two characters and navigates on Enter', async () => {
    const seen: string[] = []
    server.use(
      http.get('*/api/search', ({ request }) => {
        seen.push(new URL(request.url).searchParams.get('q') ?? '')
        return HttpResponse.json({ data: GROUPS })
      }),
    )
    const { user, router } = renderMenu()

    await user.keyboard('{Control>}k{/Control}')
    await user.type(await screen.findByRole('combobox'), 'zelda')

    expect(
      await screen.findByRole('option', { name: /^Zelda Streamer\s*zeldastream$/ }),
    ).toBeVisible()
    expect(screen.getByText('Orders')).toBeInTheDocument()
    // Debounced: the intermediate keystrokes never reach the API.
    expect(seen).toEqual(['zelda'])

    await user.keyboard('{Enter}')
    await waitFor(() => expect(router.state.location.pathname).toBe('/clients/12'))
  })

  it('shows an empty state when nothing matches', async () => {
    server.use(http.get('*/api/search', () => HttpResponse.json({ data: [] })))
    const { user } = renderMenu()

    await user.keyboard('{Control>}k{/Control}')
    await user.type(await screen.findByRole('combobox'), 'qqqq')

    expect(await screen.findByText(/No matching records for/)).toBeInTheDocument()
  })

  it('reports a failed search without breaking the screen list', async () => {
    server.use(
      http.get('*/api/search', () =>
        HttpResponse.json({ message: 'Server Error' }, { status: 500 }),
      ),
    )
    const { user } = renderMenu()

    await user.keyboard('{Control>}k{/Control}')
    await user.type(await screen.findByRole('combobox'), 'ord')

    expect(await screen.findByRole('alert')).toHaveTextContent('Search is unavailable')
    expect(screen.getByRole('option', { name: /Orders/ })).toBeInTheDocument()
  })
})
