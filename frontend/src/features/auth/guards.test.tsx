import { screen } from '@testing-library/react'
import { Can } from './Can'
import { RequireAuth, RequirePermission } from './guards'
import { makeUser, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderRoutes } from '@/test/render'

const routes = [
  {
    path: '/users',
    element: (
      <RequireAuth>
        <RequirePermission permission="users.view">
          <p>User list</p>
        </RequirePermission>
      </RequireAuth>
    ),
  },
  { path: '/403', element: <p>Forbidden page</p> },
  { path: '/login', element: <p>Login page</p> },
]

describe('route guards', () => {
  it('renders the page when the user has the permission', () => {
    renderRoutes(routes, { route: '/users', user: makeUser() })
    expect(screen.getByText('User list')).toBeInTheDocument()
  })

  it('redirects to the 403 page without the permission', () => {
    const { router } = renderRoutes(routes, {
      route: '/users',
      user: makeUser({ roles: ['sales_executive'] }, SALES_EXECUTIVE_PERMISSIONS),
    })
    expect(screen.getByText('Forbidden page')).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/403')
  })

  it('accepts any one permission from a list', () => {
    renderRoutes(
      [
        {
          path: '/',
          element: (
            <RequirePermission permission={['leads.view-all', 'leads.view-own']}>
              <p>Leads</p>
            </RequirePermission>
          ),
        },
      ],
      { user: makeUser({}, ['leads.view-own']) },
    )
    expect(screen.getByText('Leads')).toBeInTheDocument()
  })

  it('sends signed-out users to /login with a redirect back', () => {
    const { router } = renderRoutes(routes, { route: '/users?page=2', user: null })
    expect(screen.getByText('Login page')).toBeInTheDocument()
    expect(router.state.location.search).toBe(`?redirect=${encodeURIComponent('/users?page=2')}`)
  })
})

describe('<Can>', () => {
  it('shows children or the fallback', () => {
    renderRoutes(
      [
        {
          path: '/',
          element: (
            <>
              <Can permission="leads.create">
                <button type="button">New lead</button>
              </Can>
              <Can permission="leads.delete" fallback={<span>No delete</span>}>
                <button type="button">Delete</button>
              </Can>
            </>
          ),
        },
      ],
      { user: makeUser({}, ['leads.create']) },
    )
    expect(screen.getByRole('button', { name: 'New lead' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument()
    expect(screen.getByText('No delete')).toBeInTheDocument()
  })
})
