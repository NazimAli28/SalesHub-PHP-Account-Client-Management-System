import { screen, waitFor, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser, paginate, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import type { Team } from '../api'
import TeamsPage from './TeamsPage'

function makeTeam(id: number, overrides: Partial<Team> = {}): Team {
  return {
    id,
    name: `Unit ${id} Alpha`,
    display_name: `Unit ${id} Alpha`,
    floor: 3,
    shift: { value: 'evening', label: 'Evening' },
    team_lead_id: 4,
    team_lead: { id: 4, name: 'Ayla Mercer', username: 'agent1' },
    members_count: 5,
    workstations_count: 2,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

describe('TeamsPage', () => {
  it('lists teams with lead, floor, shift and member count', async () => {
    server.use(http.get('*/api/teams', () => HttpResponse.json(paginate([makeTeam(1)]))))
    renderWithProviders(<TeamsPage />, { route: '/teams', user: makeUser() })

    expect(await screen.findByText('Unit 1 Alpha')).toBeInTheDocument()
    expect(screen.getByText('Ayla Mercer')).toBeInTheDocument()
    expect(screen.getByText('Evening')).toBeInTheDocument()
    expect(screen.getByText('5')).toBeInTheDocument()
  })

  it('shows the server message when a team cannot be deleted (422)', async () => {
    const message = 'This team still has members or workstations. Move them to another team first.'
    server.use(
      http.get('*/api/teams', () => HttpResponse.json(paginate([makeTeam(1)]))),
      http.delete('*/api/teams/1', () =>
        HttpResponse.json({ message, errors: { team: [message] } }, { status: 422 }),
      ),
    )
    const { user } = renderWithProviders(<TeamsPage />, { route: '/teams', user: makeUser() })

    await user.click(await screen.findByRole('button', { name: 'Actions for Unit 1 Alpha' }))
    await user.click(await screen.findByRole('menuitem', { name: 'Delete' }))
    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Delete team' }))

    expect(await screen.findByText(message)).toBeInTheDocument()
    // The dialog stays open so the user can read the message and cancel.
    expect(screen.getByRole('alertdialog')).toBeInTheDocument()
  })

  it('shows 422 messages inline on the create form', async () => {
    server.use(
      http.get('*/api/teams', () => HttpResponse.json(paginate([makeTeam(1)]))),
      http.post('*/api/teams', () =>
        HttpResponse.json(
          {
            message: 'The name has already been taken.',
            errors: {
              name: ['The name has already been taken.'],
              team_lead_id: ['The team lead must be an active team lead who is not on a team yet.'],
            },
          },
          { status: 422 },
        ),
      ),
    )
    const { user } = renderWithProviders(<TeamsPage />, { route: '/teams', user: makeUser() })

    await user.click(await screen.findByRole('button', { name: 'New team' }))
    const dialog = within(await screen.findByRole('dialog'))
    await user.type(dialog.getByLabelText(/^Name/), 'Unit 1 Alpha')
    await user.type(dialog.getByLabelText(/^Floor/), '4')
    await user.click(dialog.getByRole('button', { name: 'Create team' }))

    expect(await dialog.findByText('The name has already been taken.')).toBeInTheDocument()
    expect(
      dialog.getByText('The team lead must be an active team lead who is not on a team yet.'),
    ).toBeInTheDocument()
    expect(dialog.getByLabelText(/^Name/)).toHaveAttribute('aria-invalid', 'true')
  })

  it('lists the members of a team in a sheet', async () => {
    server.use(
      http.get('*/api/teams', () => HttpResponse.json(paginate([makeTeam(1)]))),
      http.get('*/api/teams/1', () =>
        HttpResponse.json({
          data: makeTeam(1, {
            members: [
              { id: 4, name: 'Ayla Mercer', username: 'agent1' },
              { id: 5, name: 'Jo Hart', username: 'agent2' },
            ],
          }),
        }),
      ),
    )
    const { user } = renderWithProviders(<TeamsPage />, { route: '/teams', user: makeUser() })

    await user.click(await screen.findByRole('button', { name: 'Actions for Unit 1 Alpha' }))
    await user.click(await screen.findByRole('menuitem', { name: 'View members' }))

    const list = await screen.findByRole('list', { name: 'Team members' })
    expect(within(list).getByText('Jo Hart')).toBeInTheDocument()
    expect(within(list).getByText('Team lead')).toBeInTheDocument()
  })

  it('is read-only without teams.manage', async () => {
    server.use(http.get('*/api/teams', () => HttpResponse.json(paginate([makeTeam(1)]))))
    const { user } = renderWithProviders(<TeamsPage />, {
      route: '/teams',
      user: makeUser({ roles: ['sales_executive'] }, SALES_EXECUTIVE_PERMISSIONS),
    })

    await screen.findByText('Unit 1 Alpha')
    expect(screen.queryByRole('button', { name: 'New team' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Actions for Unit 1 Alpha' }))
    expect(await screen.findByRole('menuitem', { name: 'View members' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Edit' })).not.toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Delete' })).not.toBeInTheDocument()
  })

  it('writes the shift filter to the URL', async () => {
    let requested: URL | undefined
    server.use(
      http.get('*/api/teams', ({ request }) => {
        requested = new URL(request.url)
        return HttpResponse.json(paginate([makeTeam(1)]))
      }),
    )
    const { user, router } = renderWithProviders(<TeamsPage />, {
      route: '/teams',
      user: makeUser(),
    })

    await screen.findByText('Unit 1 Alpha')
    await user.click(screen.getAllByRole('button', { name: /^Shift/ })[0]!)
    await user.click(await screen.findByRole('option', { name: 'Night' }))

    await waitFor(() => expect(router.state.location.search).toBe('?shift=night'))
    await waitFor(() => expect(requested!.searchParams.get('filter[shift]')).toBe('night'))
  })
})
