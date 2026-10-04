import { screen, waitFor, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import type { User } from '@/api/types'
import type { Permission } from '@/lib/permissions'
import { makeUser, paginate } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import UsersPage from './UsersPage'

function apiUser(id: number, overrides: Partial<User> = {}): User {
  return {
    id,
    name: `Person ${id}`,
    username: `person${id}`,
    email: `person${id}@example.com`,
    avatar_url: null,
    is_active: true,
    team_id: 1,
    workstation_id: null,
    last_login_at: '2026-10-01T09:00:00Z',
    roles: ['sales_executive'],
    team: { id: 1, name: 'Unit 1 Alpha', floor: 3, shift: 'evening' },
    workstation: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

/** Support: manages users but not admin/support accounts (no `users.manage-privileged`). */
const SUPPORT_PERMISSIONS: Permission[] = [
  'users.view',
  'users.create',
  'users.update',
  'users.deactivate',
  'teams.view',
  'workstations.view',
]

const teamsHandler = http.get('*/api/teams', () => HttpResponse.json(paginate([])))

function useUsersList(users: User[]) {
  server.use(
    teamsHandler,
    http.get('*/api/users', () => HttpResponse.json(paginate(users))),
  )
}

describe('UsersPage', () => {
  it('shows the role, team, status and last login of each user', async () => {
    useUsersList([apiUser(2, { name: 'Ayla Mercer', roles: ['team_lead'], is_active: false })])
    renderWithProviders(<UsersPage />, { route: '/users', user: makeUser() })

    expect(await screen.findByText('Ayla Mercer')).toBeInTheDocument()
    expect(screen.getByText('Team Lead')).toBeInTheDocument()
    expect(screen.getByText('Unit 1 Alpha')).toBeInTheDocument()
    expect(screen.getByText('Inactive')).toBeInTheDocument()
  })

  it('offers every role to an admin when creating a user', async () => {
    useUsersList([apiUser(2)])
    const { user } = renderWithProviders(<UsersPage />, { route: '/users', user: makeUser() })

    await user.click(await screen.findByRole('button', { name: 'New user' }))
    await user.click(await screen.findByRole('combobox', { name: /Role/ }))

    const names = screen.getAllByRole('option').map((option) => option.textContent)
    expect(names).toEqual(['Admin', 'Support', 'Team Lead', 'Sales Executive'])
  })

  it('offers support only the roles it may assign (no admin or support)', async () => {
    useUsersList([apiUser(2)])
    const { user } = renderWithProviders(<UsersPage />, {
      route: '/users',
      user: makeUser({ id: 9, roles: ['support'] }, SUPPORT_PERMISSIONS),
    })

    await user.click(await screen.findByRole('button', { name: 'New user' }))
    await user.click(await screen.findByRole('combobox', { name: /Role/ }))

    const names = screen.getAllByRole('option').map((option) => option.textContent)
    expect(names).toEqual(['Team Lead', 'Sales Executive'])
  })

  it('hides all actions on admin and support users from a non-privileged actor', async () => {
    useUsersList([
      apiUser(1, { name: 'Robin Vale', roles: ['admin'] }),
      apiUser(2, { name: 'Ayla Mercer' }),
      apiUser(3, { name: 'Sam Support', roles: ['support'] }),
    ])
    const { user } = renderWithProviders(<UsersPage />, {
      route: '/users',
      user: makeUser({ id: 9, roles: ['support'] }, [...SUPPORT_PERMISSIONS, 'users.delete']),
    })

    await user.click(await screen.findByRole('button', { name: 'Actions for Ayla Mercer' }))
    expect(await screen.findByRole('menuitem', { name: 'Edit' })).toBeInTheDocument()
    expect(screen.getByRole('menuitem', { name: 'Deactivate' })).toBeInTheDocument()
    expect(screen.getByRole('menuitem', { name: 'Delete' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Actions for Robin Vale' })).not.toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: 'Actions for Sam Support' }),
    ).not.toBeInTheDocument()
  })

  it('does not offer deactivate or delete on yourself, even as a privileged admin', async () => {
    useUsersList([apiUser(1, { name: 'Robin Vale', roles: ['admin'] })])
    const { user } = renderWithProviders(<UsersPage />, {
      route: '/users',
      user: makeUser({ id: 1 }),
    })

    await user.click(await screen.findByRole('button', { name: 'Actions for Robin Vale' }))
    expect(await screen.findByRole('menuitem', { name: 'Edit' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Deactivate' })).not.toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Delete' })).not.toBeInTheDocument()
  })

  it('asks for confirmation before deactivating, then calls the API', async () => {
    useUsersList([apiUser(2, { name: 'Ayla Mercer' })])
    let called = false
    server.use(
      http.patch('*/api/users/2/deactivate', () => {
        called = true
        return HttpResponse.json({ data: apiUser(2, { is_active: false }) })
      }),
    )
    const { user } = renderWithProviders(<UsersPage />, { route: '/users', user: makeUser() })

    await user.click(await screen.findByRole('button', { name: 'Actions for Ayla Mercer' }))
    await user.click(await screen.findByRole('menuitem', { name: 'Deactivate' }))

    const dialog = await screen.findByRole('alertdialog')
    expect(within(dialog).getByText(/signed out everywhere/)).toBeInTheDocument()
    expect(called).toBe(false)
    await user.click(within(dialog).getByRole('button', { name: 'Deactivate user' }))

    expect(await screen.findByText('User deactivated')).toBeInTheDocument()
    expect(called).toBe(true)
  })

  it('shows the server message when the API refuses (403)', async () => {
    useUsersList([apiUser(2, { name: 'Ayla Mercer' })])
    server.use(
      http.patch('*/api/users/2/deactivate', () =>
        HttpResponse.json({ message: 'This action is unauthorized.' }, { status: 403 }),
      ),
    )
    const { user } = renderWithProviders(<UsersPage />, { route: '/users', user: makeUser() })

    await user.click(await screen.findByRole('button', { name: 'Actions for Ayla Mercer' }))
    await user.click(await screen.findByRole('menuitem', { name: 'Deactivate' }))
    await user.click(
      within(await screen.findByRole('alertdialog')).getByRole('button', {
        name: 'Deactivate user',
      }),
    )

    expect(await screen.findByText('This action is unauthorized.')).toBeInTheDocument()
  })

  it('maps 422 errors onto the create form fields', async () => {
    useUsersList([apiUser(2)])
    let body: Record<string, unknown> | undefined
    server.use(
      http.post('*/api/users', async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>
        return HttpResponse.json(
          {
            message: 'The email has already been taken.',
            errors: { email: ['The email has already been taken.'] },
          },
          { status: 422 },
        )
      }),
    )
    const { user } = renderWithProviders(<UsersPage />, { route: '/users', user: makeUser() })

    await user.click(await screen.findByRole('button', { name: 'New user' }))
    const sheet = within(await screen.findByRole('dialog'))
    await user.type(sheet.getByLabelText(/Full name/), 'Jo Hart')
    await user.type(sheet.getByLabelText(/^Username/), 'jo.hart')
    await user.type(sheet.getByLabelText(/^Email/), 'taken@example.com')
    await user.type(sheet.getByLabelText(/^Password/), 'Strong-Pass1!')
    await user.click(sheet.getByRole('button', { name: 'Create user' }))

    const message = await sheet.findByText('The email has already been taken.')
    expect(message).toBeInTheDocument()
    expect(sheet.getByLabelText(/^Email/)).toHaveAttribute('aria-invalid', 'true')
    expect(body).toMatchObject({
      name: 'Jo Hart',
      username: 'jo.hart',
      email: 'taken@example.com',
      role: 'sales_executive',
      team_id: null,
    })
  })

  it('checks the password policy before sending', async () => {
    useUsersList([apiUser(2)])
    const { user } = renderWithProviders(<UsersPage />, { route: '/users', user: makeUser() })

    await user.click(await screen.findByRole('button', { name: 'New user' }))
    const sheet = within(await screen.findByRole('dialog'))
    await user.type(sheet.getByLabelText(/Full name/), 'Jo Hart')
    await user.type(sheet.getByLabelText(/^Username/), 'jo.hart')
    await user.type(sheet.getByLabelText(/^Email/), 'jo@example.com')
    await user.type(sheet.getByLabelText(/^Password/), 'short')
    await user.click(sheet.getByRole('button', { name: 'Create user' }))

    expect(await screen.findByText('The password must be at least 10 characters.')).toBeVisible()
  })

  it('writes filters to the URL and sends them to the API', async () => {
    let requested: URL | undefined
    server.use(
      teamsHandler,
      http.get('*/api/users', ({ request }) => {
        requested = new URL(request.url)
        return HttpResponse.json(paginate([apiUser(2)]))
      }),
    )
    const { user, router } = renderWithProviders(<UsersPage />, {
      route: '/users?role=support&active=true&q=ayla',
      user: makeUser(),
    })

    await screen.findByText('Person 2')
    expect(requested!.searchParams.get('filter[role]')).toBe('support')
    expect(requested!.searchParams.get('filter[active]')).toBe('true')
    expect(requested!.searchParams.get('filter[search]')).toBe('ayla')
    expect(requested!.searchParams.get('sort')).toBe('name')

    await user.click(screen.getByRole('button', { name: /^Role/ }))
    await user.click(await screen.findByRole('option', { name: 'Team Lead' }))

    await waitFor(() => expect(router.state.location.search).toContain('role=support%2Cteam_lead'))
  })
})
