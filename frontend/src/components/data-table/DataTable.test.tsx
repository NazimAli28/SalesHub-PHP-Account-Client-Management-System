import { screen, within } from '@testing-library/react'
import type { Lead, Paginated } from '@/api/types'
import { makeLead, makeUser, paginate } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import {
  createColumnHelper,
  DataTable,
  DataTableColumnHeader,
  useDataTableParams,
  type DataTableQuery,
} from '.'

const column = createColumnHelper<Lead>()
const columns = column.columns([
  column.accessor((lead) => lead.client?.name ?? '', {
    id: 'client',
    header: 'Client',
    meta: { label: 'Client' },
  }),
  column.accessor('contacted_on', {
    id: 'contacted_on',
    enableSorting: true,
    header: ({ column }) => <DataTableColumnHeader column={column} title="Contacted" />,
    meta: { label: 'Contacted' },
  }),
])

function query(
  data: Paginated<Lead> | undefined,
  overrides: Partial<DataTableQuery<Lead>> = {},
): DataTableQuery<Lead> {
  return {
    data,
    isPending: !data,
    isError: false,
    isFetching: false,
    error: null,
    refetch: vi.fn(),
    ...overrides,
  }
}

function Harness({ result }: { result: DataTableQuery<Lead> }) {
  const table = useDataTableParams({ filterKeys: ['stage'], defaultSort: '-contacted_on' })
  return <DataTable label="Leads" columns={columns} query={result} state={table} />
}

function renderTable(result: DataTableQuery<Lead>, route = '/leads') {
  return renderWithProviders(<Harness result={result} />, { route, user: makeUser() })
}

describe('DataTable', () => {
  it('renders a row per record', () => {
    renderTable(query(paginate([makeLead(1), makeLead(2)])))
    const table = screen.getByRole('table', { name: 'Leads' })
    // 1 header row + 2 body rows
    expect(within(table).getAllByRole('row')).toHaveLength(3)
    expect(screen.getByText('Streamer 1')).toBeInTheDocument()
    expect(screen.getByText('Showing 1–2 of 2')).toBeInTheDocument()
  })

  it('marks the default sort with aria-sort and toggles sorting into the URL', async () => {
    const { user, router } = renderTable(query(paginate([makeLead(1)])))
    const header = screen.getByRole('columnheader', { name: /Contacted/ })
    expect(header).toHaveAttribute('aria-sort', 'descending')
    expect(screen.getByRole('columnheader', { name: 'Client' })).not.toHaveAttribute('aria-sort')

    await user.click(within(header).getByRole('button'))
    expect(new URLSearchParams(router.state.location.search).get('sort')).toBe('contacted_on')
    expect(screen.getByRole('columnheader', { name: /Contacted/ })).toHaveAttribute(
      'aria-sort',
      'ascending',
    )

    // Back to descending = the endpoint default, which is left out of the URL.
    await user.click(
      within(screen.getByRole('columnheader', { name: /Contacted/ })).getByRole('button'),
    )
    expect(new URLSearchParams(router.state.location.search).get('sort')).toBeNull()
    expect(screen.getByRole('columnheader', { name: /Contacted/ })).toHaveAttribute(
      'aria-sort',
      'descending',
    )
  })

  it('pages through results via the URL', async () => {
    const page1 = paginate([makeLead(1)], { page: 1, perPage: 25, total: 60 })
    const { user, router } = renderTable(query(page1), '/leads?stage=new')

    expect(screen.getByText('Page 1 of 3')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Previous page' })).toBeDisabled()

    await user.click(screen.getByRole('button', { name: 'Next page' }))
    const search = new URLSearchParams(router.state.location.search)
    expect(search.get('page')).toBe('2')
    expect(search.get('stage')).toBe('new')
  })

  it('shows the empty state, and a "no matches" state when filtered', async () => {
    const { unmount } = renderTable(query(paginate([])))
    expect(screen.getByText('No leads yet')).toBeInTheDocument()
    unmount()

    const { user, router } = renderTable(query(paginate([])), '/leads?stage=won')
    expect(screen.getByText('No matches')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Clear filters' }))
    expect(router.state.location.search).toBe('')
  })

  it('shows skeleton rows while loading and an error state with retry', async () => {
    const { unmount } = renderTable(query(undefined, { isFetching: true }))
    expect(screen.getByRole('table', { name: 'Leads' })).toHaveAttribute('aria-busy', 'true')
    expect(screen.queryByText('Streamer 1')).not.toBeInTheDocument()
    unmount()

    const refetch = vi.fn()
    const { user } = renderTable(
      query(undefined, {
        isPending: false,
        isError: true,
        error: new Error('Server unavailable'),
        refetch,
      }),
    )
    expect(screen.getByText('We could not load leads')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(refetch).toHaveBeenCalled()
  })
})
