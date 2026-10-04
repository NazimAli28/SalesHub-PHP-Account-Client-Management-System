import { act, screen, waitFor, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { Route, Routes } from 'react-router'
import { makeUser } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import type { Permission } from '@/lib/permissions'
import { makePlatformAccount, makeSocialAccount } from '../test-fixtures'
import PlatformAccountDetailPage from './PlatformAccountDetailPage'

const REVEALER: Permission[] = [
  'platform-accounts.view-own',
  'platform-accounts.reveal-credentials',
  'social-accounts.view-own',
  'social-accounts.reveal-credentials',
]

function renderDetail(user = makeUser({ roles: ['sales_executive'] }, REVEALER)) {
  return renderWithProviders(
    <Routes>
      <Route path="/platform-accounts/:platformAccountId" element={<PlatformAccountDetailPage />} />
    </Routes>,
    { route: '/platform-accounts/1', user },
  )
}

function useAccount(overrides = {}) {
  server.use(
    http.get('*/api/platform-accounts/1', () =>
      HttpResponse.json({
        data: makePlatformAccount(1, {
          social_accounts: [makeSocialAccount(7, { username: 'pixel_studio' })],
          ...overrides,
        }),
      }),
    ),
  )
}

// Popovers and sheets are slow under jsdom when several test files run at once.

describe('PlatformAccountDetailPage', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows the header, details and linked social accounts', async () => {
    useAccount()
    renderDetail()

    expect(
      await screen.findByRole('heading', { level: 1, name: /vale\.accounts1@example\.com/ }),
    ).toBeInTheDocument()
    expect(screen.getByText(/Workstation WS-03 · Unit 1 Alpha/)).toBeInTheDocument()
    expect(screen.getByText('@pixel_studio')).toBeInTheDocument()
    // Credentials are only described as set / not set here.
    expect(screen.queryByText(/hunter2/)).not.toBeInTheDocument()
  })

  it('reveals the chosen credentials, masked, and hides them again after 30 seconds', async () => {
    // Fake timers must be on before the reveal starts its 30 s countdown.
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let body: unknown
    server.use(
      http.post('*/api/platform-accounts/1/reveal', async ({ request }) => {
        body = await request.json()
        return HttpResponse.json({
          data: { email_password: 'Maple-Trail-41', discord_password: 'Granite-Fox-72' },
        })
      }),
    )
    useAccount()
    const { user } = renderDetail()

    await user.click(await screen.findByRole('button', { name: 'Reveal credentials' }))
    const revealed = within(await screen.findByTestId('revealed-values'))
    const emailPassword = revealed.getByLabelText('Email password')
    expect(body).toEqual({ fields: ['email_password', 'discord_password'] })

    // Masked by default; the eye button shows it.
    expect(emailPassword).toHaveAttribute('type', 'password')
    expect(emailPassword).toHaveValue('Maple-Trail-41')
    expect(revealed.getByLabelText('Discord password')).toHaveValue('Granite-Fox-72')
    await user.click(revealed.getByRole('button', { name: 'Show Email password' }))
    expect(revealed.getByLabelText('Email password')).toHaveAttribute('type', 'text')
    expect(revealed.getByRole('button', { name: 'Copy Email password' })).toBeEnabled()
    expect(screen.getByText(/audit log/)).toBeInTheDocument()

    // Hide automatically.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(30_000)
    })
    await waitFor(() => expect(screen.queryByTestId('revealed-values')).not.toBeInTheDocument())
    expect(screen.getByRole('button', { name: 'Reveal credentials' })).toBeInTheDocument()
  })

  it('hides revealed values on demand', async () => {
    server.use(
      http.post('*/api/platform-accounts/1/reveal', () =>
        HttpResponse.json({ data: { email_password: 'Maple-Trail-41' } }),
      ),
    )
    useAccount({ has_discord_password: false })
    const { user } = renderDetail()

    await user.click(await screen.findByRole('button', { name: 'Reveal credentials' }))
    await screen.findByTestId('revealed-values')
    await user.click(screen.getByRole('button', { name: 'Hide now' }))
    expect(screen.queryByTestId('revealed-values')).not.toBeInTheDocument()
  })

  it('explains a throttled reveal (429) instead of crashing', async () => {
    server.use(
      http.post('*/api/platform-accounts/1/reveal', () =>
        HttpResponse.json(
          { message: 'Too Many Attempts.' },
          { status: 429, headers: { 'Retry-After': '42' } },
        ),
      ),
    )
    useAccount()
    const { user } = renderDetail()

    await user.click(await screen.findByRole('button', { name: 'Reveal credentials' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/too quickly.*42 seconds/)
    // The button is still there to try again.
    expect(screen.getByRole('button', { name: 'Reveal credentials' })).toBeEnabled()
  })

  it('does not offer the reveal panel without the permission', async () => {
    useAccount()
    renderDetail(
      makeUser({ roles: ['sales_executive'] }, [
        'platform-accounts.view-own',
        'social-accounts.view-own',
      ]),
    )
    await screen.findByText('@pixel_studio')
    expect(screen.queryByRole('button', { name: 'Reveal credentials' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Reveal password for/ })).not.toBeInTheDocument()
  })

  it('reveals a linked social account password with its own permission', async () => {
    let body: unknown
    server.use(
      http.post('*/api/social-accounts/7/reveal', async ({ request }) => {
        body = await request.json()
        return HttpResponse.json({ data: { password: 'Cedar-Lamp-90' } })
      }),
    )
    useAccount()
    const { user } = renderDetail()

    await user.click(
      await screen.findByRole('button', { name: 'Reveal password for @pixel_studio' }),
    )
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Reveal password' }))

    expect(await within(dialog).findByLabelText('Password')).toHaveValue('Cedar-Lamp-90')
    expect(body).toEqual({ fields: ['password'] })
  })

  it('shows a banner for a pending change and pauses editing', async () => {
    useAccount({
      pending_change: {
        id: 5,
        action: { value: 'update', label: 'Update' },
        fields: ['notes'],
        requested_by: { id: 4, name: 'Ayla Mercer', username: 'agent1' },
        requested_at: '2026-10-03T09:00:00Z',
      },
    })
    renderDetail(
      makeUser({ roles: ['sales_executive'] }, [...REVEALER, 'platform-accounts.request-change']),
    )

    const banner = await screen.findByText(/is pending approval/)
    expect(banner).toHaveTextContent(/Update is pending approval/)
    expect(banner).toHaveTextContent(/Ayla Mercer/)
    expect(screen.getByRole('button', { name: 'Request edit' })).toBeDisabled()
  })

  it('says the user has no access when the API answers 403', async () => {
    server.use(
      http.get('*/api/platform-accounts/1', () =>
        HttpResponse.json({ message: 'Forbidden.' }, { status: 403 }),
      ),
    )
    renderDetail()
    expect(await screen.findByText("You don't have access to this record")).toBeInTheDocument()
  })
})
