import { screen, waitFor, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser, paginate, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import type { Service } from '../api'
import ServicesPage from './ServicesPage'

function makeService(id: number, overrides: Partial<Service> = {}): Service {
  return {
    id,
    name: `Emote pack ${id}`,
    slug: `emote-pack-${id}`,
    category: { value: 'emotes', label: 'Emotes' },
    description: 'Six custom emotes.',
    base_price: { amount_cents: 12500, currency: 'USD', formatted: '$125.00' },
    is_active: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

describe('ServicesPage', () => {
  it('lists the catalog with category, price and status', async () => {
    server.use(
      http.get('*/api/services', () =>
        HttpResponse.json(paginate([makeService(1), makeService(2, { is_active: false })])),
      ),
    )
    renderWithProviders(<ServicesPage />, { route: '/services', user: makeUser() })

    expect(await screen.findByText('Emote pack 1')).toBeInTheDocument()
    expect(screen.getAllByText('$125.00')).toHaveLength(2)
    expect(screen.getAllByText('Emotes').length).toBeGreaterThan(0)
    expect(screen.getByText('Inactive')).toBeInTheDocument()
  })

  it('is read-only without services.manage', async () => {
    server.use(http.get('*/api/services', () => HttpResponse.json(paginate([makeService(1)]))))
    renderWithProviders(<ServicesPage />, {
      route: '/services',
      user: makeUser({ roles: ['sales_executive'] }, SALES_EXECUTIVE_PERMISSIONS),
    })

    expect(await screen.findByText('Emote pack 1')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'New service' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Actions for/ })).not.toBeInTheDocument()
    expect(screen.getByText(/Read-only/)).toBeInTheDocument()
  })

  it('sends the price in cents when creating a service', async () => {
    let body: Record<string, unknown> | undefined
    server.use(
      http.get('*/api/services', () => HttpResponse.json(paginate([makeService(1)]))),
      http.post('*/api/services', async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>
        return HttpResponse.json({ data: makeService(9) }, { status: 201 })
      }),
    )
    const { user } = renderWithProviders(<ServicesPage />, {
      route: '/services',
      user: makeUser(),
    })

    await user.click(await screen.findByRole('button', { name: 'New service' }))
    const sheet = within(await screen.findByRole('dialog'))
    await user.type(sheet.getByLabelText(/^Name/), 'Stream overlay')
    await user.type(sheet.getByLabelText(/^Base price/), '249.99')
    await user.click(sheet.getByRole('button', { name: 'Create service' }))

    expect(await screen.findByText('Service created')).toBeInTheDocument()
    expect(body).toMatchObject({
      name: 'Stream overlay',
      category: 'branding',
      base_price_cents: 24999,
      is_active: true,
    })
  })

  it('deactivates a service from the row menu', async () => {
    let body: unknown
    server.use(
      http.get('*/api/services', () => HttpResponse.json(paginate([makeService(1)]))),
      http.patch('*/api/services/1', async ({ request }) => {
        body = await request.json()
        return HttpResponse.json({ data: makeService(1, { is_active: false }) })
      }),
    )
    const { user } = renderWithProviders(<ServicesPage />, {
      route: '/services',
      user: makeUser(),
    })

    await user.click(await screen.findByRole('button', { name: 'Actions for Emote pack 1' }))
    await user.click(await screen.findByRole('menuitem', { name: 'Deactivate' }))

    expect(await screen.findByText('Service deactivated')).toBeInTheDocument()
    expect(body).toEqual({ is_active: false })
  })

  it('keeps filters in the URL and sends them to the API', async () => {
    let requested: URL | undefined
    server.use(
      http.get('*/api/services', ({ request }) => {
        requested = new URL(request.url)
        return HttpResponse.json(paginate([makeService(1)]))
      }),
    )
    const { user, router } = renderWithProviders(<ServicesPage />, {
      route: '/services?category=emotes',
      user: makeUser(),
    })

    await screen.findByText('Emote pack 1')
    expect(requested!.searchParams.get('filter[category]')).toBe('emotes')

    await user.click(screen.getByRole('button', { name: /^Status/ }))
    await user.click(await screen.findByRole('option', { name: 'Active' }))

    await waitFor(() => expect(router.state.location.search).toContain('active=true'))
  })
})
