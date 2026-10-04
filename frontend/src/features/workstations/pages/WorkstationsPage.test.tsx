import { screen, waitFor, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser, paginate, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import type { Workstation } from '../api'
import WorkstationsPage from './WorkstationsPage'

function makeWorkstation(id: number, overrides: Partial<Workstation> = {}): Workstation {
  return {
    id,
    code: `WS-0${id}`,
    label: 'Window seat',
    is_active: true,
    team_id: 1,
    team: { id: 1, name: 'Unit 1 Alpha', floor: 3, shift: null },
    users_count: 1,
    platform_accounts_count: 3,
    users: [{ id: 4, name: 'Ayla Mercer', username: 'agent1' }],
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

const teams = http.get('*/api/teams', () => HttpResponse.json(paginate([])))

describe('WorkstationsPage', () => {
  it('lists workstations with their user, team and account count', async () => {
    let requested: URL | undefined
    server.use(
      teams,
      http.get('*/api/workstations', ({ request }) => {
        requested = new URL(request.url)
        return HttpResponse.json(paginate([makeWorkstation(1)]))
      }),
    )
    renderWithProviders(<WorkstationsPage />, { route: '/workstations', user: makeUser() })

    expect(await screen.findByText('WS-01')).toBeInTheDocument()
    expect(screen.getByText('Ayla Mercer')).toBeInTheDocument()
    expect(screen.getByText('Unit 1 Alpha')).toBeInTheDocument()
    expect(screen.getByText('3')).toBeInTheDocument()
    expect(requested!.searchParams.get('include')).toBe('users')
  })

  it('shows the server message when a workstation cannot be deleted (422)', async () => {
    const message = 'This workstation still has users or platform accounts. Reassign them first.'
    server.use(
      teams,
      http.get('*/api/workstations', () => HttpResponse.json(paginate([makeWorkstation(1)]))),
      http.delete('*/api/workstations/1', () =>
        HttpResponse.json({ message, errors: { workstation: [message] } }, { status: 422 }),
      ),
    )
    const { user } = renderWithProviders(<WorkstationsPage />, {
      route: '/workstations',
      user: makeUser(),
    })

    await user.click(await screen.findByRole('button', { name: 'Actions for WS-01' }))
    await user.click(await screen.findByRole('menuitem', { name: 'Delete' }))
    await user.click(
      within(await screen.findByRole('alertdialog')).getByRole('button', {
        name: 'Delete workstation',
      }),
    )

    expect(await screen.findByText(message)).toBeInTheDocument()
  })

  it('is read-only without workstations.manage', async () => {
    server.use(
      teams,
      http.get('*/api/workstations', () => HttpResponse.json(paginate([makeWorkstation(1)]))),
    )
    renderWithProviders(<WorkstationsPage />, {
      route: '/workstations',
      user: makeUser({ roles: ['sales_executive'] }, SALES_EXECUTIVE_PERMISSIONS),
    })

    await screen.findByText('WS-01')
    expect(screen.queryByRole('button', { name: 'New workstation' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Actions for/ })).not.toBeInTheDocument()
  })

  it('writes the status filter to the URL', async () => {
    server.use(
      teams,
      http.get('*/api/workstations', () => HttpResponse.json(paginate([makeWorkstation(1)]))),
    )
    const { user, router } = renderWithProviders(<WorkstationsPage />, {
      route: '/workstations',
      user: makeUser(),
    })

    await screen.findByText('WS-01')
    await user.click(screen.getByRole('button', { name: /^Status/ }))
    await user.click(await screen.findByRole('option', { name: 'Inactive' }))
    await waitFor(() => expect(router.state.location.search).toBe('?active=false'))
  })
})
