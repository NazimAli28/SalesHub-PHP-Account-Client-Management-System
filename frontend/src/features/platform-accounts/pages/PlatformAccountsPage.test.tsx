import { screen, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser, paginate } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import type { Permission } from '@/lib/permissions'
import { makePlatformAccount } from '../test-fixtures'
import PlatformAccountsPage from './PlatformAccountsPage'

const VIEW_ONLY: Permission[] = ['platform-accounts.view-own']
const REQUESTER: Permission[] = [
  'platform-accounts.view-own',
  'platform-accounts.request-new',
  'platform-accounts.request-change',
]

// Popovers and sheets are slow under jsdom when several test files run at once.

beforeEach(() => {
  server.use(
    http.get('*/api/platform-accounts', () =>
      HttpResponse.json(paginate([makePlatformAccount(1), makePlatformAccount(2)])),
    ),
    http.get('*/api/workstations', () =>
      HttpResponse.json(
        paginate([{ id: 3, code: 'WS-03', label: 'Window seat', team_id: 2, is_active: true }]),
      ),
    ),
    http.get('*/api/teams', () => HttpResponse.json(paginate([{ id: 2, name: 'Unit 1 Alpha' }]))),
  )
})

describe('PlatformAccountsPage', () => {
  it('requests the page described by the URL and renders the rows', async () => {
    let requested: URL | undefined
    server.use(
      http.get('*/api/platform-accounts', ({ request }) => {
        requested = new URL(request.url)
        return HttpResponse.json(
          paginate([makePlatformAccount(1, { standing: { value: 'limited', label: 'Limited' } })], {
            total: 1,
          }),
        )
      }),
    )

    renderWithProviders(<PlatformAccountsPage />, {
      route:
        '/platform-accounts?standing=limited&assigned=0&workstation=3&team=2&batch_from=2026-09-01&batch_to=2026-09-30&q=vale&sort=standing&page=2&size=10',
      user: makeUser(),
    })

    expect(await screen.findByText('vale.accounts1@example.com')).toBeInTheDocument()
    expect(
      screen.getByText('Limited', { selector: '[data-tone] *, [data-tone]' }),
    ).toBeInTheDocument()
    expect(Object.fromEntries(requested!.searchParams)).toEqual({
      'page[number]': '2',
      'page[size]': '10',
      sort: 'standing',
      'filter[search]': 'vale',
      'filter[standing]': 'limited',
      'filter[workstation]': '3',
      'filter[team]': '2',
      'filter[assigned]': '0',
      'filter[batch_from]': '2026-09-01',
      'filter[batch_to]': '2026-09-30',
    })
  })

  it('writes filter changes to the URL', async () => {
    const { user, router } = renderWithProviders(<PlatformAccountsPage />, {
      route: '/platform-accounts',
      user: makeUser(),
    })
    await screen.findByText('vale.accounts1@example.com')

    await user.click(screen.getByRole('button', { name: 'Standing' }))
    await user.click(await screen.findByRole('option', { name: 'Limited' }))
    expect(router.state.location.search).toBe('?standing=limited')

    await user.click(screen.getByRole('button', { name: 'Assignment' }))
    await user.click(await screen.findByRole('option', { name: 'Unassigned' }))
    expect(new URLSearchParams(router.state.location.search).get('assigned')).toBe('0')
  })

  it('shows only View in the row menu, and no create buttons, without write permissions', async () => {
    const { user } = renderWithProviders(<PlatformAccountsPage />, {
      route: '/platform-accounts',
      user: makeUser({ roles: ['sales_executive'] }, VIEW_ONLY),
    })
    expect(
      await screen.findByRole('button', { name: 'Actions for vale.accounts1@example.com' }),
    ).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'New account' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Request new accounts' })).not.toBeInTheDocument()
    // Workstation and team filters need their own view permissions.
    expect(screen.queryByRole('button', { name: 'Workstation' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Team' })).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Actions for vale.accounts1@example.com' }))
    expect(await screen.findByRole('menuitem', { name: 'View' })).toBeInTheDocument()
    for (const name of [
      'Edit',
      'Request edit',
      'Assign workstation',
      'Change standing',
      'Delete',
    ]) {
      expect(screen.queryByRole('menuitem', { name })).not.toBeInTheDocument()
    }
  })

  it('offers every action to users who hold the permissions', async () => {
    const { user } = renderWithProviders(<PlatformAccountsPage />, {
      route: '/platform-accounts',
      user: makeUser(),
    })
    await user.click(
      await screen.findByRole('button', { name: 'Actions for vale.accounts1@example.com' }),
    )
    for (const name of ['View', 'Edit', 'Assign workstation', 'Change standing', 'Delete']) {
      expect(await screen.findByRole('menuitem', { name })).toBeInTheDocument()
    }
    expect(screen.getByRole('button', { name: 'New account' })).toBeInTheDocument()
  })

  it('says "Sent for approval" when requesting new accounts (202)', async () => {
    let body: unknown
    server.use(
      http.post('*/api/platform-accounts/request-new', async ({ request }) => {
        body = await request.json()
        return HttpResponse.json(
          { data: { id: 9, status: { value: 'pending', label: 'Pending' } } },
          { status: 202 },
        )
      }),
    )
    const { user } = renderWithProviders(<PlatformAccountsPage />, {
      route: '/platform-accounts',
      user: makeUser(
        { roles: ['sales_executive'], workstation_id: 3, workstation: { id: 3, code: 'WS-03' } },
        REQUESTER,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Request new accounts' }))
    const dialog = await screen.findByRole('dialog')
    const quantity = within(dialog).getByLabelText(/How many accounts/)
    await user.clear(quantity)
    await user.type(quantity, '3')
    await user.click(within(dialog).getByRole('button', { name: 'Send request' }))

    expect(await screen.findByText('Sent for approval')).toBeInTheDocument()
    expect(body).toEqual({ quantity: 3 })
  })

  it('asks request-change users to "Send for approval" and handles the 202', async () => {
    let body: unknown
    server.use(
      http.patch('*/api/platform-accounts/1', async ({ request }) => {
        body = await request.json()
        return HttpResponse.json(
          { data: { id: 9, status: { value: 'pending', label: 'Pending' } } },
          { status: 202 },
        )
      }),
    )
    const { user } = renderWithProviders(<PlatformAccountsPage />, {
      route: '/platform-accounts',
      user: makeUser({ roles: ['sales_executive'] }, REQUESTER),
    })

    await user.click(
      await screen.findByRole('button', { name: 'Actions for vale.accounts1@example.com' }),
    )
    await user.click(await screen.findByRole('menuitem', { name: 'Request edit' }))
    const sheet = await screen.findByRole('dialog')
    // Request-change users cannot send credentials, so the fields are not even offered.
    expect(within(sheet).queryByLabelText(/Email password/)).not.toBeInTheDocument()

    const notes = within(sheet).getByLabelText('Notes')
    await user.clear(notes)
    await user.type(notes, 'Needs a new recovery phone')
    await user.type(within(sheet).getByLabelText('Reason for the change'), 'Phone changed')
    await user.click(within(sheet).getByRole('button', { name: 'Send for approval' }))

    expect((await screen.findAllByText('Sent for approval')).length).toBeGreaterThan(0)
    expect(body).toEqual({ notes: 'Needs a new recovery phone', reason: 'Phone changed' })
  })

  it('never prefills credentials when editing', async () => {
    const { user } = renderWithProviders(<PlatformAccountsPage />, {
      route: '/platform-accounts',
      user: makeUser(),
    })

    await user.click(
      await screen.findByRole('button', { name: 'Actions for vale.accounts1@example.com' }),
    )
    await user.click(await screen.findByRole('menuitem', { name: 'Edit' }))
    const sheet = await screen.findByRole('dialog')

    for (const label of [
      'Email password',
      'Discord password',
      'Recovery phone',
      'Phone holder name',
    ]) {
      expect(within(sheet).getByLabelText(new RegExp(label))).toHaveValue('')
    }
    // It says whether a secret is stored, without showing it.
    expect(within(sheet).getByLabelText(/Email password/)).toHaveAttribute('placeholder', 'Set')
    expect(within(sheet).getByLabelText(/Recovery phone/)).toHaveAttribute('placeholder', 'Not set')
    expect(within(sheet).getAllByText(/Leave blank to keep it unchanged/).length).toBeGreaterThan(0)
  })
})
