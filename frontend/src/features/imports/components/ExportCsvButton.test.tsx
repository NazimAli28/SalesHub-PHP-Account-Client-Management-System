import { screen, waitFor } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import type { ListParams } from '@/api/list-params'
import { makeUser, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import { ExportCsvButton } from './ExportCsvButton'

const PARAMS: ListParams = {
  page: 3,
  pageSize: 50,
  sort: '-created_at',
  search: 'pixel',
  filters: { status: ['active', 'nurturing'], owner: [] },
}

describe('ExportCsvButton', () => {
  afterEach(() => vi.restoreAllMocks())

  it('downloads the filtered, sorted list without paging and names the file from the response', async () => {
    let requested: URL | undefined
    server.use(
      http.get('*/api/exports/clients', ({ request }) => {
        requested = new URL(request.url)
        return new HttpResponse('Id,Discord username\n1,neon_fox\n', {
          headers: {
            'Content-Type': 'text/csv',
            'Content-Disposition': 'attachment; filename="clients-2026-10-05.csv"',
          },
        })
      }),
    )
    URL.createObjectURL = vi.fn(() => 'blob:export')
    URL.revokeObjectURL = vi.fn()
    const downloads: string[] = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      downloads.push(this.download)
    })

    const { user } = renderWithProviders(<ExportCsvButton type="clients" params={PARAMS} />, {
      user: makeUser(),
    })
    await user.click(screen.getByRole('button', { name: 'Export CSV' }))

    await waitFor(() => expect(downloads).toEqual(['clients-2026-10-05.csv']))
    expect(Object.fromEntries(requested!.searchParams)).toEqual({
      sort: '-created_at',
      'filter[search]': 'pixel',
      'filter[status]': 'active,nurturing',
    })
    expect(URL.createObjectURL).toHaveBeenCalled()
  })

  it('shows an error when the export is refused', async () => {
    server.use(
      http.get('*/api/exports/leads', () =>
        HttpResponse.json({ message: 'This action is unauthorized.' }, { status: 403 }),
      ),
    )
    const { user } = renderWithProviders(<ExportCsvButton type="leads" params={PARAMS} />, {
      user: makeUser(),
    })

    await user.click(screen.getByRole('button', { name: 'Export CSV' }))

    expect(await screen.findByText('This action is unauthorized.')).toBeInTheDocument()
  })

  it('is hidden without the reports.export permission', () => {
    renderWithProviders(<ExportCsvButton type="clients" params={PARAMS} />, {
      user: makeUser({ roles: ['sales_executive'] }, SALES_EXECUTIVE_PERMISSIONS),
    })

    expect(screen.queryByRole('button', { name: 'Export CSV' })).not.toBeInTheDocument()
  })
})
