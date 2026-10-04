import { screen, waitFor } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { RedirectIfAuthenticated } from '../guards'
import { LoginForm } from './LoginForm'
import { makeUser } from '@/test/fixtures'
import { renderRoutes } from '@/test/render'
import { server } from '@/test/server'

const routes = [
  {
    path: '/login',
    element: (
      <RedirectIfAuthenticated>
        <LoginForm />
      </RedirectIfAuthenticated>
    ),
  },
  { path: '/', element: <p>Dashboard</p> },
  { path: '/leads', element: <p>Leads page</p> },
]

function renderLogin(route = '/login') {
  return renderRoutes(routes, { route, user: null })
}

describe('LoginForm', () => {
  it('validates required fields before calling the API', async () => {
    const login = vi.fn()
    server.use(http.post('*/api/auth/login', login))
    const { user } = renderLogin()

    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('Enter your username or email.')).toBeInTheDocument()
    expect(screen.getByText('Enter your password.')).toBeInTheDocument()
    expect(screen.getByLabelText('Username or email')).toHaveAttribute('aria-invalid', 'true')
    expect(login).not.toHaveBeenCalled()
  })

  it('shows the API 422 message on the login field', async () => {
    server.use(
      http.post('*/api/auth/login', () =>
        HttpResponse.json(
          {
            message: 'These credentials do not match our records.',
            errors: { login: ['These credentials do not match our records.'] },
          },
          { status: 422 },
        ),
      ),
    )
    const { user } = renderLogin()

    await user.type(screen.getByLabelText('Username or email'), 'agent1')
    await user.type(screen.getByLabelText('Password'), 'wrong-password')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(
      await screen.findByText('These credentials do not match our records.'),
    ).toBeInTheDocument()
    expect(screen.getByLabelText('Username or email')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('Password')).toHaveValue('')
  })

  it('shows a countdown and disables the button on 429', async () => {
    server.use(
      http.post('*/api/auth/login', () =>
        HttpResponse.json(
          { message: 'Too many login attempts.', code: 'too_many_attempts', retry_after: 47 },
          { status: 429, headers: { 'Retry-After': '47' } },
        ),
      ),
    )
    const { user } = renderLogin()

    await user.type(screen.getByLabelText('Username or email'), 'agent1')
    await user.type(screen.getByLabelText('Password'), 'Secret@123')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(/Too many sign-in attempts\. Try again in 4[67] seconds\./)
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeDisabled()
  })

  it('shows the inactive-account message on 403', async () => {
    server.use(
      http.post('*/api/auth/login', () =>
        HttpResponse.json(
          {
            message: 'This account has been deactivated. Contact an administrator.',
            code: 'account_inactive',
          },
          { status: 403 },
        ),
      ),
    )
    const { user } = renderLogin()

    await user.type(screen.getByLabelText('Username or email'), 'former')
    await user.type(screen.getByLabelText('Password'), 'Secret@123')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('This account has been deactivated.')
  })

  it('signs in with the right payload and follows ?redirect=', async () => {
    let body: unknown
    server.use(
      http.post('*/api/auth/login', async ({ request }) => {
        body = await request.json()
        return HttpResponse.json({ data: makeUser() })
      }),
    )
    const { user, router } = renderLogin(
      `/login?redirect=${encodeURIComponent('/leads?stage=new')}`,
    )

    await user.type(screen.getByLabelText('Username or email'), '  admin  ')
    await user.type(screen.getByLabelText('Password'), 'Secret@123')
    await user.click(screen.getByLabelText('Keep me signed in on this device'))
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('Leads page')).toBeInTheDocument()
    expect(router.state.location.search).toBe('?stage=new')
    expect(body).toEqual({ login: 'admin', password: 'Secret@123', remember: true })
  })

  it('ignores redirects to other sites', async () => {
    const { user } = renderLogin(`/login?redirect=${encodeURIComponent('//evil.example.com')}`)
    await user.type(screen.getByLabelText('Username or email'), 'admin')
    await user.type(screen.getByLabelText('Password'), 'Secret@123')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))
    await waitFor(() => expect(screen.getByText('Dashboard')).toBeInTheDocument())
  })
})
