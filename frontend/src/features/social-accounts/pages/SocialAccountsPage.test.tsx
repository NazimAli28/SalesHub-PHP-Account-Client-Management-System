import { screen, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser, paginate } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import type { Permission } from '@/lib/permissions'
import { makeSocialAccount } from '../test-fixtures'
import SocialAccountsPage from './SocialAccountsPage'

const VIEW_ONLY: Permission[] = ['social-accounts.view-own']
const REQUESTER: Permission[] = ['social-accounts.view-own', 'social-accounts.request-change']

const ROW = 'Actions for Instagram @pixel_studio_1'

// Popovers and sheets are slow under jsdom when several test files run at once.

beforeEach(() => {
  server.use(
    http.get('*/api/social-accounts', () =>
      HttpResponse.json(paginate([makeSocialAccount(1), makeSocialAccount(2)])),
    ),
    http.get('*/api/platform-accounts', () =>
      HttpResponse.json(
        paginate([
          { id: 10, email: 'vale.accounts@example.com', discord_username: 'vale', standing: null },
        ]),
      ),
    ),
  )
})

describe('SocialAccountsPage', () => {
  it('requests the page described by the URL and renders the rows', async () => {
    let requested: URL | undefined
    server.use(
      http.get('*/api/social-accounts', ({ request }) => {
        requested = new URL(request.url)
        return HttpResponse.json(paginate([makeSocialAccount(1)], { total: 1 }))
      }),
    )

    renderWithProviders(<SocialAccountsPage />, {
      route:
        '/social-accounts?platform=instagram,x&in_use=0&platform_account=10&q=pixel&sort=username',
      user: makeUser(),
    })

    expect(await screen.findByText('@pixel_studio_1')).toBeInTheDocument()
    expect(Object.fromEntries(requested!.searchParams)).toEqual({
      'page[number]': '1',
      'page[size]': '25',
      sort: 'username',
      'filter[search]': 'pixel',
      'filter[platform]': 'instagram,x',
      'filter[in_use]': '0',
      'filter[platform_account]': '10',
      include: 'platformAccount',
    })
  })

  it('writes filter changes to the URL', async () => {
    const { user, router } = renderWithProviders(<SocialAccountsPage />, {
      route: '/social-accounts',
      user: makeUser(),
    })
    await screen.findByText('@pixel_studio_1')

    await user.click(screen.getByRole('button', { name: 'Platform' }))
    await user.click(await screen.findByRole('option', { name: 'TikTok' }))
    expect(router.state.location.search).toBe('?platform=tiktok')

    await user.click(screen.getByRole('button', { name: 'Usage' }))
    await user.click(await screen.findByRole('option', { name: 'In use' }))
    expect(new URLSearchParams(router.state.location.search).get('in_use')).toBe('1')
  })

  it('hides create and row actions from users without write or reveal permissions', async () => {
    renderWithProviders(<SocialAccountsPage />, {
      route: '/social-accounts',
      user: makeUser({ roles: ['sales_executive'] }, VIEW_ONLY),
    })
    await screen.findByText('@pixel_studio_1')
    expect(screen.queryByRole('button', { name: 'New social account' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: ROW })).not.toBeInTheDocument()
  })

  it('offers reveal, edit and delete to a user who holds those permissions', async () => {
    const { user } = renderWithProviders(<SocialAccountsPage />, {
      route: '/social-accounts',
      user: makeUser(),
    })
    await user.click(await screen.findByRole('button', { name: ROW }))
    for (const name of ['Reveal password', 'Edit', 'Delete']) {
      expect(await screen.findByRole('menuitem', { name })).toBeInTheDocument()
    }
    expect(screen.getByRole('button', { name: 'New social account' })).toBeInTheDocument()
  })

  it('reveals a password from the row menu, masked', async () => {
    server.use(
      http.post('*/api/social-accounts/1/reveal', () =>
        HttpResponse.json({ data: { password: 'Cedar-Lamp-90' } }),
      ),
    )
    const { user } = renderWithProviders(<SocialAccountsPage />, {
      route: '/social-accounts',
      user: makeUser({ roles: ['sales_executive'] }, [
        ...VIEW_ONLY,
        'social-accounts.reveal-credentials',
      ]),
    })

    await user.click(await screen.findByRole('button', { name: ROW }))
    expect(screen.queryByRole('menuitem', { name: 'Edit' })).not.toBeInTheDocument()
    await user.click(await screen.findByRole('menuitem', { name: 'Reveal password' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Reveal password' }))

    const field = await within(dialog).findByLabelText('Password')
    expect(field).toHaveAttribute('type', 'password')
    expect(field).toHaveValue('Cedar-Lamp-90')
  })

  it('never prefills the password when editing', async () => {
    const { user } = renderWithProviders(<SocialAccountsPage />, {
      route: '/social-accounts',
      user: makeUser(),
    })
    await user.click(await screen.findByRole('button', { name: ROW }))
    await user.click(await screen.findByRole('menuitem', { name: 'Edit' }))
    const sheet = await screen.findByRole('dialog')

    const password = within(sheet).getByLabelText(/^Password/)
    expect(password).toHaveValue('')
    expect(password).toHaveAttribute('placeholder', 'Set. Type to replace it')
    expect(within(sheet).getByLabelText(/Username/)).toHaveValue('pixel_studio_1')
  })

  it('says "Sent for approval" when a request-change user edits (202)', async () => {
    let body: unknown
    server.use(
      http.patch('*/api/social-accounts/1', async ({ request }) => {
        body = await request.json()
        return HttpResponse.json(
          { data: { id: 9, status: { value: 'pending', label: 'Pending' } } },
          { status: 202 },
        )
      }),
    )
    const { user } = renderWithProviders(<SocialAccountsPage />, {
      route: '/social-accounts',
      user: makeUser({ roles: ['sales_executive'] }, REQUESTER),
    })

    await user.click(await screen.findByRole('button', { name: ROW }))
    await user.click(await screen.findByRole('menuitem', { name: 'Request edit' }))
    const sheet = await screen.findByRole('dialog')
    expect(within(sheet).queryByLabelText(/^Password/)).not.toBeInTheDocument()

    await user.click(within(sheet).getByRole('checkbox', { name: 'Currently in use' }))
    await user.click(within(sheet).getByRole('button', { name: 'Send for approval' }))

    expect((await screen.findAllByText('Sent for approval')).length).toBeGreaterThan(0)
    expect(body).toEqual({ is_in_use: true })
  })
})
