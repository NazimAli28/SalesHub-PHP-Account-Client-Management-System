import { screen, waitFor, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import ProfilePage from './ProfilePage'
import { makeUser } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'

const SESSIONS = [
  {
    id: 'a1',
    ip_address: '127.0.0.1',
    device: 'Chrome on Windows',
    last_active_at: '2026-10-05T09:00:00Z',
    is_current: true,
  },
  {
    id: 'b2',
    ip_address: '203.0.113.7',
    device: 'Safari on iOS',
    last_active_at: '2026-10-04T09:00:00Z',
    is_current: false,
  },
]

const CODES = ['AAAAA-11111', 'BBBBB-22222', 'CCCCC-33333', 'DDDDD-44444']

beforeEach(() => {
  server.use(http.get('*/api/auth/sessions', () => HttpResponse.json({ data: SESSIONS })))
})

function twoFactorCard() {
  return screen
    .getByText('Two-step verification', { selector: '[data-slot="card-title"]' })
    .closest('[data-slot="card"]') as HTMLElement
}

describe('ProfilePage security', () => {
  it('turns on two-step verification and shows the recovery codes once', async () => {
    let confirmBody: unknown
    server.use(
      http.post('*/api/auth/two-factor', () =>
        HttpResponse.json({
          data: {
            secret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
            otpauth_url: 'otpauth://totp/SalesHub:admin%40example.com?secret=X',
            qr_code: 'data:image/svg+xml;base64,PHN2Zy8+',
          },
        }),
      ),
      http.post('*/api/auth/two-factor/confirm', async ({ request }) => {
        confirmBody = await request.json()
        return HttpResponse.json({ data: { recovery_codes: CODES } })
      }),
      http.get('*/api/auth/me', () =>
        HttpResponse.json({ data: makeUser({ two_factor_enabled: true }) }),
      ),
    )
    const { user } = renderWithProviders(<ProfilePage />, { user: makeUser() })

    await user.click(screen.getByRole('button', { name: 'Turn on two-step verification' }))

    const qr = await screen.findByRole('img', {
      name: 'QR code to add SalesHub to your authenticator app',
    })
    expect(qr).toHaveAttribute('src', 'data:image/svg+xml;base64,PHN2Zy8+')
    expect(screen.getByLabelText('Setup key')).toHaveTextContent('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP')

    await user.type(screen.getByLabelText('Code from the app'), '654321')
    await user.click(screen.getByRole('button', { name: 'Confirm and turn on' }))

    const list = await screen.findByRole('list', { name: 'Recovery codes' })
    expect(within(list).getAllByRole('listitem')).toHaveLength(4)
    expect(confirmBody).toEqual({ code: '654321' })
    expect(screen.getByRole('button', { name: 'Download .txt' })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'I saved my codes' }))
    expect(screen.queryByRole('list', { name: 'Recovery codes' })).not.toBeInTheDocument()
    expect(await within(twoFactorCard()).findByText('On')).toBeInTheDocument()
  })

  it('shows a wrong setup code inline', async () => {
    server.use(
      http.post('*/api/auth/two-factor', () =>
        HttpResponse.json({
          data: {
            secret: 'ABC',
            otpauth_url: 'otpauth://x',
            qr_code: 'data:image/svg+xml;base64,',
          },
        }),
      ),
      http.post('*/api/auth/two-factor/confirm', () =>
        HttpResponse.json(
          {
            message: 'This code is invalid or has already been used.',
            errors: { code: ['This code is invalid or has already been used.'] },
          },
          { status: 422 },
        ),
      ),
    )
    const { user } = renderWithProviders(<ProfilePage />, { user: makeUser() })

    await user.click(screen.getByRole('button', { name: 'Turn on two-step verification' }))
    await user.type(await screen.findByLabelText('Code from the app'), '000000')
    await user.click(screen.getByRole('button', { name: 'Confirm and turn on' }))

    expect(
      await screen.findByText('This code is invalid or has already been used.'),
    ).toBeInTheDocument()
  })

  it('needs the password and a code to turn two-step verification off', async () => {
    let body: unknown
    server.use(
      http.delete('*/api/auth/two-factor', async ({ request }) => {
        body = await request.json()
        const { password, code } = body as { password: string; code: string }
        if (password !== 'Demo@12345') {
          return HttpResponse.json(
            {
              message: 'The password is incorrect.',
              errors: { password: ['The password is incorrect.'] },
            },
            { status: 422 },
          )
        }
        if (code !== '123456') {
          return HttpResponse.json(
            {
              message: 'This code is invalid or has already been used.',
              errors: { code: ['This code is invalid or has already been used.'] },
            },
            { status: 422 },
          )
        }
        return new HttpResponse(null, { status: 204 })
      }),
      http.get('*/api/auth/me', () => HttpResponse.json({ data: makeUser() })),
    )
    const { user } = renderWithProviders(<ProfilePage />, {
      user: makeUser({ two_factor_enabled: true }),
    })

    await user.click(screen.getByRole('button', { name: 'Turn off' }))
    const dialog = await screen.findByRole('dialog', { name: 'Turn off two-step verification?' })

    // Both fields are required before anything is sent.
    await user.type(within(dialog).getByLabelText(/Current password/), 'wrong')
    await user.click(within(dialog).getByRole('button', { name: 'Turn off' }))
    expect(
      await within(dialog).findByText(
        'Enter a code from your authenticator app or a recovery code.',
      ),
    ).toBeInTheDocument()
    expect(body).toBeUndefined()

    await user.type(within(dialog).getByLabelText(/Authentication code/), '000000')
    await user.click(within(dialog).getByRole('button', { name: 'Turn off' }))
    expect(await within(dialog).findByText('The password is incorrect.')).toBeInTheDocument()

    await user.clear(within(dialog).getByLabelText(/Current password/))
    await user.type(within(dialog).getByLabelText(/Current password/), 'Demo@12345')
    await user.click(within(dialog).getByRole('button', { name: 'Turn off' }))
    expect(
      await within(dialog).findByText('This code is invalid or has already been used.'),
    ).toBeInTheDocument()

    await user.clear(within(dialog).getByLabelText(/Authentication code/))
    await user.type(within(dialog).getByLabelText(/Authentication code/), '123456')
    await user.click(within(dialog).getByRole('button', { name: 'Turn off' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(body).toEqual({ password: 'Demo@12345', code: '123456' })
    expect(await within(twoFactorCard()).findByText('Off')).toBeInTheDocument()
  })

  it('regenerates recovery codes after the password check', async () => {
    server.use(
      http.post('*/api/auth/two-factor/recovery-codes', () =>
        HttpResponse.json({ data: { recovery_codes: CODES } }),
      ),
    )
    const { user } = renderWithProviders(<ProfilePage />, {
      user: makeUser({ two_factor_enabled: true }),
    })

    await user.click(screen.getByRole('button', { name: 'Regenerate recovery codes' }))
    const dialog = await screen.findByRole('dialog', { name: 'Regenerate recovery codes?' })
    await user.type(within(dialog).getByLabelText(/Current password/), 'Demo@12345')
    await user.click(within(dialog).getByRole('button', { name: 'Regenerate' }))

    const list = await screen.findByRole('list', { name: 'Recovery codes' })
    expect(within(list).getByText('AAAAA-11111')).toBeInTheDocument()
  })

  it('lists sessions and signs out the others', async () => {
    let remaining = SESSIONS
    server.use(
      http.get('*/api/auth/sessions', () => HttpResponse.json({ data: remaining })),
      http.delete('*/api/auth/sessions/others', () => {
        remaining = SESSIONS.filter((session) => session.is_current)
        return HttpResponse.json({ data: { revoked: 1 } })
      }),
    )
    const { user } = renderWithProviders(<ProfilePage />, { user: makeUser() })

    const list = await screen.findByRole('list', { name: 'Active sessions' })
    expect(within(list).getAllByRole('listitem')).toHaveLength(2)
    expect(within(list).getByText('This device')).toBeInTheDocument()
    expect(within(list).getByText('Safari on iOS')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Sign out other sessions' }))
    const dialog = await screen.findByRole('dialog', { name: 'Sign out other sessions?' })
    await user.type(within(dialog).getByLabelText(/Current password/), 'Demo@12345')
    await user.click(within(dialog).getByRole('button', { name: 'Sign out others' }))

    expect(await screen.findByText('Signed out 1 other session.')).toBeInTheDocument()
    await waitFor(() =>
      expect(
        within(screen.getByRole('list', { name: 'Active sessions' })).getAllByRole('listitem'),
      ).toHaveLength(1),
    )
    expect(screen.getByRole('button', { name: 'Sign out other sessions' })).toBeDisabled()
  })

  it('shows an error state when sessions fail to load', async () => {
    server.use(
      http.get('*/api/auth/sessions', () =>
        HttpResponse.json({ message: 'Server error' }, { status: 500 }),
      ),
    )
    renderWithProviders(<ProfilePage />, { user: makeUser() })

    expect(await screen.findByText('We could not load your sessions')).toBeInTheDocument()
  })

  it('explains demo mode and disables two-step setup and the password change', async () => {
    renderWithProviders(<ProfilePage />, { user: makeUser({ demo_mode: true }) })

    expect(screen.getByRole('note')).toHaveTextContent('This is the public demo')
    expect(screen.getByRole('button', { name: 'Turn on two-step verification' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Update password' })).toBeDisabled()
    expect(screen.getByLabelText(/Current password/)).toBeDisabled()
    await screen.findByRole('list', { name: 'Active sessions' })
    expect(
      screen.queryByRole('button', { name: 'Sign out other sessions' }),
    ).not.toBeInTheDocument()
    expect(screen.getByText(/only this browser is listed/)).toBeInTheDocument()
  })
})
