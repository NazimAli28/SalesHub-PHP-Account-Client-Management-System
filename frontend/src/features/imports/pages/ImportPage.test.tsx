import { screen, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser, paginate, SALES_EXECUTIVE_PERMISSIONS } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import type { ImportField, ImportRecord, PreviewResult, UploadedImport } from '../types'
import ImportPage from './ImportPage'

const FIELDS: ImportField[] = [
  {
    key: 'client_discord_username',
    label: 'Client Discord username',
    required: true,
    example: 'pixelpanda42',
    hint: '',
  },
  { key: 'client_name', label: 'Client name', required: false, example: '', hint: '' },
  { key: 'stage', label: 'Stage', required: false, example: '', hint: '' },
]

function makeImport(overrides: Partial<ImportRecord> = {}): ImportRecord {
  return {
    id: 1,
    type: { value: 'leads', label: 'Leads' },
    status: { value: 'uploaded', label: 'Uploaded' },
    original_filename: 'leads.csv',
    headers: ['Discord', 'Name', 'Stage'],
    mapping: null,
    fields: FIELDS,
    total_rows: 2,
    processed_rows: 0,
    created_rows: 0,
    failed_rows: 0,
    errors: [],
    started_at: null,
    finished_at: null,
    created_at: '2026-10-05T09:00:00Z',
    ...overrides,
  }
}

function makeUpload(overrides: Partial<UploadedImport> = {}): UploadedImport {
  return {
    ...makeImport(),
    sample_rows: [{ Discord: 'neon_fox', Name: 'Jordan Rivera', Stage: 'new' }],
    suggested_mapping: {
      Discord: 'client_discord_username',
      Name: 'client_name',
      Stage: 'stage',
    },
    ...overrides,
  }
}

const PREVIEW: PreviewResult = {
  rows: [
    {
      row: 2,
      values: { Discord: 'neon_fox', Name: 'Jordan Rivera', Stage: 'new' },
      valid: true,
      errors: {},
    },
    {
      row: 3,
      values: { Discord: 'mossy', Name: 'Sam Okoye', Stage: 'flying' },
      valid: false,
      errors: { stage: ['The selected stage is invalid.'] },
    },
  ],
  summary: { total_rows: 2, checked: 2, valid: 1, invalid: 1 },
}

const csv = (name = 'leads.csv') =>
  new File(['Discord,Name,Stage\nneon_fox,Jordan Rivera,new\n'], name, { type: 'text/csv' })

function useWizardApi(overrides: { upload?: UploadedImport } = {}) {
  const calls = { preview: [] as unknown[], start: [] as unknown[] }
  let polls = 0
  server.use(
    http.get('*/api/imports', () => HttpResponse.json(paginate([]))),
    http.post('*/api/imports', () =>
      HttpResponse.json({ data: overrides.upload ?? makeUpload() }, { status: 201 }),
    ),
    http.post('*/api/imports/1/preview', async ({ request }) => {
      calls.preview.push(await request.json())
      return HttpResponse.json({ data: PREVIEW })
    }),
    http.post('*/api/imports/1/start', async ({ request }) => {
      calls.start.push(await request.json())
      return HttpResponse.json(
        { data: makeImport({ status: { value: 'queued', label: 'Queued' } }) },
        { status: 202 },
      )
    }),
    http.get('*/api/imports/1', () => {
      polls += 1
      return HttpResponse.json({
        data:
          polls < 2
            ? makeImport({
                status: { value: 'processing', label: 'Processing' },
                processed_rows: 1,
              })
            : makeImport({
                status: { value: 'completed', label: 'Completed' },
                processed_rows: 2,
                created_rows: 1,
                failed_rows: 1,
                errors: [{ row: 3, column: 'Stage', field: 'stage', message: 'Invalid stage.' }],
              }),
      })
    }),
  )
  return calls
}

const IMPORTER = makeUser({ roles: ['support'] })

describe('ImportPage', () => {
  it('walks through upload, mapping, preview, import and result', async () => {
    const calls = useWizardApi()
    const { user } = renderWithProviders(<ImportPage />, { route: '/imports', user: IMPORTER })

    const steps = screen.getByRole('navigation', { name: 'Import steps' })
    expect(within(steps).getByText('Upload').closest('li')).toHaveAttribute('aria-current', 'step')

    // 1. Upload
    await user.upload(screen.getByLabelText('CSV file'), csv())
    await user.click(screen.getByRole('button', { name: 'Upload and continue' }))

    // 2. Map: the suggestion is pre-selected
    expect(await screen.findByText('Match your columns')).toBeInTheDocument()
    expect(within(steps).getByText('Map columns').closest('li')).toHaveAttribute(
      'aria-current',
      'step',
    )
    expect(screen.getByLabelText('Discord')).toHaveValue('client_discord_username')
    await user.click(screen.getByRole('button', { name: 'Preview rows' }))

    // 3. Preview: summary and the failing row
    expect(await screen.findByTestId('preview-summary')).toHaveTextContent(
      'Checked 2 of 2 rows: 1 ready, 1 with problems.',
    )
    expect(screen.getByText('The selected stage is invalid.')).toBeInTheDocument()
    expect(screen.getByText('flying').closest('tr')).toHaveAttribute('data-invalid', 'true')
    expect(calls.preview[0]).toEqual({
      mapping: { Discord: 'client_discord_username', Name: 'client_name', Stage: 'stage' },
    })
    await user.click(screen.getByRole('button', { name: 'Continue' }))

    // 4. Import: start, then progress until it completes
    expect(await screen.findByText('Ready to import')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Start import' }))
    expect(await screen.findByRole('progressbar', { name: 'Import progress' })).toBeInTheDocument()
    expect(calls.start).toHaveLength(1)

    // 5. Result
    expect(await screen.findByText('Import finished')).toBeInTheDocument()
    expect(screen.getByTestId('created-count')).toHaveTextContent('1')
    expect(screen.getByTestId('failed-count')).toHaveTextContent('1')
    expect(screen.getByText('Row 3 (Stage): Invalid stage.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'View leads' })).toHaveAttribute('href', '/leads')
    expect(screen.getByRole('button', { name: 'Download failed rows' })).toBeInTheDocument()
  })

  it('asks for the required columns before previewing', async () => {
    const calls = useWizardApi({
      upload: makeUpload({
        suggested_mapping: { Discord: null, Name: 'client_name', Stage: 'stage' },
      }),
    })
    const { user } = renderWithProviders(<ImportPage />, { route: '/imports', user: IMPORTER })

    await user.upload(screen.getByLabelText('CSV file'), csv())
    await user.click(screen.getByRole('button', { name: 'Upload and continue' }))
    await user.click(await screen.findByRole('button', { name: 'Preview rows' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Map a column to: Client Discord username.',
    )
    expect(calls.preview).toHaveLength(0)

    // Mapping the same field twice is rejected too.
    await user.selectOptions(screen.getByLabelText('Discord'), 'client_discord_username')
    await user.selectOptions(screen.getByLabelText('Name'), 'client_discord_username')
    await user.click(screen.getByRole('button', { name: 'Preview rows' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(
      '"Client Discord username" is mapped to more than one column.',
    )
    expect(calls.preview).toHaveLength(0)
  })

  it('rejects files with the wrong extension or size before uploading', async () => {
    useWizardApi()
    const { user } = renderWithProviders(<ImportPage />, { route: '/imports', user: IMPORTER })
    const input = screen.getByLabelText('CSV file')

    await user.upload(input, new File(['x'], 'notes.txt.exe', { type: 'text/plain' }))
    // `accept` filters the picker; a drop bypasses it, so the same check also runs on any file.
    expect(screen.queryByRole('button', { name: 'Upload and continue' })).toBeDisabled()

    const big = new File([new Uint8Array(2 * 1024 * 1024 + 1)], 'big.csv', { type: 'text/csv' })
    await user.upload(input, big)
    expect(await screen.findByRole('alert')).toHaveTextContent('The file is larger than 2 MB.')
    expect(screen.getByRole('button', { name: 'Upload and continue' })).toBeDisabled()
  })

  it('shows the server message when the upload is refused', async () => {
    server.use(
      http.get('*/api/imports', () => HttpResponse.json(paginate([]))),
      http.post('*/api/imports', () =>
        HttpResponse.json(
          {
            message: 'The file has more than 2,000 data rows.',
            errors: { file: ['The file has more than 2,000 data rows.'] },
          },
          { status: 422 },
        ),
      ),
    )
    const { user } = renderWithProviders(<ImportPage />, { route: '/imports', user: IMPORTER })

    await user.upload(screen.getByLabelText('CSV file'), csv())
    await user.click(screen.getByRole('button', { name: 'Upload and continue' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('more than 2,000 data rows')
  })

  it('offers only the import types the user may use', () => {
    server.use(http.get('*/api/imports', () => HttpResponse.json(paginate([]))))
    renderWithProviders(<ImportPage />, {
      route: '/imports',
      user: makeUser({ roles: ['support'] }, [...SALES_EXECUTIVE_PERMISSIONS, 'clients.import']),
    })

    expect(screen.getByRole('radio', { name: /Clients/ })).toBeChecked()
    expect(screen.queryByRole('radio', { name: /Leads/ })).not.toBeInTheDocument()
  })

  it('lists recent imports', async () => {
    server.use(
      http.get('*/api/imports', () =>
        HttpResponse.json(
          paginate([
            makeImport({
              id: 7,
              original_filename: 'september.csv',
              status: { value: 'completed', label: 'Completed' },
              created_rows: 40,
              failed_rows: 2,
            }),
          ]),
        ),
      ),
    )
    renderWithProviders(<ImportPage />, { route: '/imports', user: IMPORTER })

    expect(await screen.findByText('september.csv')).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Download failed rows of september.csv' }),
    ).toBeInTheDocument()
  })

  it('explains when the user cannot import anything', () => {
    renderWithProviders(<ImportPage />, {
      route: '/imports',
      user: makeUser({ roles: ['sales_executive'] }, SALES_EXECUTIVE_PERMISSIONS),
    })

    expect(screen.getByText('You cannot import data')).toBeInTheDocument()
  })
})
