import { screen } from '@testing-library/react'
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

function mockTwoFactorLogin() {
  server.use(http.post('*/api/auth/login', () => HttpResponse.json({ data: { two_factor: true } })))
}

async function signIn(route = '/login') {
  const rendered = renderRoutes(routes, { route, user: null })
  await rendered.user.type(screen.getByLabelText('Username or email'), 'agent1')
  await rendered.user.type(screen.getByLabelText('Password'), 'Secret@123')
  await rendered.user.click(screen.getByRole('button', { name: 'Sign in' }))
  await screen.findByRole('heading', { name: 'Two-step verification' })
  return rendered
}

describe('two-factor sign-in step', () => {
  it('asks for the code, then signs in and follows ?redirect=', async () => {
    mockTwoFactorLogin()
    let body: unknown
    server.use(
      http.post('*/api/auth/two-factor-challenge', async ({ request }) => {
        body = await request.json()
        return HttpResponse.json({ data: makeUser({ two_factor_enabled: true }) })
      }),
    )
    const { user, router } = await signIn(`/login?redirect=${encodeURIComponent('/leads')}`)

    await user.type(screen.getByLabelText('Authentication code'), '123456')

    expect(await screen.findByText('Leads page')).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/leads')
    expect(body).toEqual({ code: '123456' })
  })

  it('validates the code before calling the API', async () => {
    mockTwoFactorLogin()
    const challenge = vi.fn()
    server.use(http.post('*/api/auth/two-factor-challenge', challenge))
    const { user } = await signIn()

    await user.type(screen.getByLabelText('Authentication code'), '123')
    await user.click(screen.getByRole('button', { name: 'Verify' }))

    expect(
      await screen.findByText('Enter the 6-digit code from your authenticator app.'),
    ).toBeInTheDocument()
    expect(challenge).not.toHaveBeenCalled()
  })

  it('shows a wrong code inline', async () => {
    mockTwoFactorLogin()
    server.use(
      http.post('*/api/auth/two-factor-challenge', () =>
        HttpResponse.json(
          {
            message: 'This code is invalid or has already been used.',
            errors: { code: ['This code is invalid or has already been used.'] },
          },
          { status: 422 },
        ),
      ),
    )
    const { user } = await signIn()

    await user.type(screen.getByLabelText('Authentication code'), '000000')

    expect(
      await screen.findByText('This code is invalid or has already been used.'),
    ).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Two-step verification' })).toBeInTheDocument()
  })

  it('accepts a recovery code instead', async () => {
    mockTwoFactorLogin()
    let body: unknown
    server.use(
      http.post('*/api/auth/two-factor-challenge', async ({ request }) => {
        body = await request.json()
        return HttpResponse.json({ data: makeUser() })
      }),
    )
    const { user } = await signIn()

    await user.click(screen.getByRole('button', { name: 'Use a recovery code instead' }))
    await user.type(screen.getByLabelText('Recovery code'), ' ABCDE-12345 ')
    await user.click(screen.getByRole('button', { name: 'Verify' }))

    expect(await screen.findByText('Dashboard')).toBeInTheDocument()
    expect(body).toEqual({ recovery_code: 'ABCDE-12345' })
  })

  it('goes back to the password step when the attempt expired', async () => {
    mockTwoFactorLogin()
    server.use(
      http.post('*/api/auth/two-factor-challenge', () =>
        HttpResponse.json(
          {
            message: 'Your sign-in attempt has expired. Please sign in again.',
            code: 'two_factor_expired',
          },
          { status: 422 },
        ),
      ),
    )
    const { user } = await signIn()

    await user.type(screen.getByLabelText('Authentication code'), '123456')

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Your sign-in attempt has expired. Please sign in again.',
    )
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeInTheDocument()
    expect(screen.getByLabelText('Password')).toHaveValue('')
  })

  it('shows a countdown on 429', async () => {
    mockTwoFactorLogin()
    server.use(
      http.post('*/api/auth/two-factor-challenge', () =>
        HttpResponse.json(
          {
            message: 'Too many verification attempts.',
            code: 'too_many_attempts',
            retry_after: 30,
          },
          { status: 429, headers: { 'Retry-After': '30' } },
        ),
      ),
    )
    const { user } = await signIn()

    await user.type(screen.getByLabelText('Authentication code'), '123456')

    expect(await screen.findByRole('alert')).toHaveTextContent(/Too many attempts\. Try again in/)
    expect(screen.getByRole('button', { name: 'Verify' })).toBeDisabled()
  })
})
