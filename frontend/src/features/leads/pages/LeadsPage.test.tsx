import { screen, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeLead, makeUser, paginate, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import LeadsPage from './LeadsPage'

describe('LeadsPage', () => {
  it('requests the page described by the URL and renders the rows', async () => {
    let requested: URL | undefined
    server.use(
      http.get('*/api/leads', ({ request }) => {
        requested = new URL(request.url)
        return HttpResponse.json(
          paginate([makeLead(1, { stage: { value: 'quoted', label: 'Quoted' } })], { total: 1 }),
        )
      }),
    )

    renderWithProviders(<LeadsPage />, {
      route: '/leads?stage=quoted&q=pixel&page=2&size=10',
      user: makeUser(),
    })

    expect(await screen.findByText('Streamer 1')).toBeInTheDocument()
    expect(
      screen.getByText('Quoted', { selector: '[data-tone] *, [data-tone]' }),
    ).toBeInTheDocument()
    expect(Object.fromEntries(requested!.searchParams)).toEqual({
      'page[number]': '2',
      'page[size]': '10',
      sort: '-contacted_on',
      'filter[search]': 'pixel',
      'filter[stage]': 'quoted',
      include: 'client,owner',
    })
  })

  it('hides create and the owner filter from users without those permissions', async () => {
    renderWithProviders(<LeadsPage />, {
      route: '/leads',
      user: makeUser(
        { roles: ['sales_executive'] },
        SALES_EXECUTIVE_PERMISSIONS.filter((p) => p !== 'leads.create'),
      ),
    })
    await screen.findByText('Streamer 1')
    expect(screen.queryByRole('button', { name: 'New lead' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Stage' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Owner' })).not.toBeInTheDocument()
  })

  it('says "Sent for approval" when a deletion is queued (202)', async () => {
    server.use(
      http.delete('*/api/leads/1', () =>
        HttpResponse.json(
          { data: { id: 9, status: { value: 'pending', label: 'Pending' } } },
          { status: 202 },
        ),
      ),
    )
    const { user } = renderWithProviders(<LeadsPage />, {
      route: '/leads',
      user: makeUser({ roles: ['sales_executive'] }, SALES_EXECUTIVE_PERMISSIONS),
    })

    await user.click(await screen.findByRole('button', { name: 'Actions for Streamer 1' }))
    await user.click(await screen.findByRole('menuitem', { name: 'Request deletion' }))
    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Send request' }))

    expect(await screen.findByText('Sent for approval')).toBeInTheDocument()
  })
})
