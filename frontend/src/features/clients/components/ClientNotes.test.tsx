import { screen, waitFor, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser, paginate } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import type { ClientNote, TimelineItem } from '../notes-api'
import { ClientNotes } from './ClientNotes'
import { ClientTimeline } from './ClientTimeline'

function makeNote(id: number, overrides: Partial<ClientNote> = {}): ClientNote {
  return {
    id,
    client_id: 5,
    body: `Note number ${id}`,
    is_pinned: false,
    author: { id: 1, name: 'Robin Vale', username: 'admin' },
    can_edit: true,
    can_delete: true,
    created_at: '2026-10-04T10:00:00Z',
    updated_at: '2026-10-04T10:00:00Z',
    ...overrides,
  }
}

const render = (ui: React.ReactElement) =>
  renderWithProviders(ui, { route: '/clients/5', user: makeUser() })

describe('ClientNotes', () => {
  it('lists notes with the author and shows the empty state', async () => {
    server.use(
      http.get('*/api/clients/5/notes', () =>
        HttpResponse.json(paginate([makeNote(1, { is_pinned: true }), makeNote(2)])),
      ),
    )
    render(<ClientNotes clientId={5} />)

    const list = await screen.findByRole('list', { name: 'Client notes' })
    expect(within(list).getByText('Note number 1')).toBeInTheDocument()
    expect(within(list).getByText('Pinned')).toBeInTheDocument()
    expect(within(list).getAllByText('Robin Vale')).toHaveLength(2)
  })

  it('shows an empty state without notes', async () => {
    server.use(http.get('*/api/clients/5/notes', () => HttpResponse.json(paginate([]))))
    render(<ClientNotes clientId={5} />)

    expect(await screen.findByText('No notes yet')).toBeInTheDocument()
  })

  it('adds a note and refreshes the list', async () => {
    const notes: ClientNote[] = []
    let posted: unknown = null
    server.use(
      http.get('*/api/clients/5/notes', () => HttpResponse.json(paginate([...notes]))),
      http.post('*/api/clients/5/notes', async ({ request }) => {
        posted = await request.json()
        notes.push(makeNote(9, { body: 'Call back on Friday' }))
        return HttpResponse.json({ data: notes[0] }, { status: 201 })
      }),
    )
    const { user } = render(<ClientNotes clientId={5} />)
    await screen.findByText('No notes yet')

    const add = screen.getByRole('button', { name: 'Add note' })
    expect(add).toBeDisabled()
    await user.type(screen.getByLabelText('New note'), '  Call back on Friday ')
    await user.click(add)

    expect(await screen.findByText('Call back on Friday')).toBeInTheDocument()
    expect(posted).toEqual({ body: 'Call back on Friday' })
    expect(screen.getByLabelText('New note')).toHaveValue('')
  })

  it('pins a note', async () => {
    let patched: unknown = null
    server.use(
      http.get('*/api/clients/5/notes', () => HttpResponse.json(paginate([makeNote(3)]))),
      http.patch('*/api/clients/5/notes/3', async ({ request }) => {
        patched = await request.json()
        return HttpResponse.json({ data: makeNote(3, { is_pinned: true }) })
      }),
    )
    const { user } = render(<ClientNotes clientId={5} />)

    await user.click(await screen.findByRole('button', { name: 'Pin note' }))

    await waitFor(() => expect(patched).toEqual({ is_pinned: true }))
  })

  it('edits an own note', async () => {
    let patched: unknown = null
    server.use(
      http.get('*/api/clients/5/notes', () => HttpResponse.json(paginate([makeNote(3)]))),
      http.patch('*/api/clients/5/notes/3', async ({ request }) => {
        patched = await request.json()
        return HttpResponse.json({ data: makeNote(3, { body: 'Reworded' }) })
      }),
    )
    const { user } = render(<ClientNotes clientId={5} />)

    await user.click(await screen.findByRole('button', { name: 'Edit note' }))
    const field = screen.getByLabelText('Note text')
    await user.clear(field)
    await user.type(field, 'Reworded')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(patched).toEqual({ body: 'Reworded' }))
    await waitFor(() => expect(screen.queryByLabelText('Note text')).not.toBeInTheDocument())
  })

  it('hides edit and delete when the API says the user may not', async () => {
    server.use(
      http.get('*/api/clients/5/notes', () =>
        HttpResponse.json(paginate([makeNote(4, { can_edit: false, can_delete: false })])),
      ),
    )
    render(<ClientNotes clientId={5} />)

    await screen.findByText('Note number 4')
    expect(screen.queryByRole('button', { name: 'Edit note' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Delete note' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Pin note' })).toBeInTheDocument()
  })

  it('asks for confirmation before deleting', async () => {
    let deleted = 0
    server.use(
      http.get('*/api/clients/5/notes', () => HttpResponse.json(paginate([makeNote(6)]))),
      http.delete('*/api/clients/5/notes/6', () => {
        deleted += 1
        return new HttpResponse(null, { status: 204 })
      }),
    )
    const { user } = render(<ClientNotes clientId={5} />)

    await user.click(await screen.findByRole('button', { name: 'Delete note' }))
    expect(deleted).toBe(0)
    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Delete note' }))
    await waitFor(() => expect(deleted).toBe(1))
  })
})

describe('ClientTimeline', () => {
  it('renders notes and activity with actors and links', async () => {
    const items: TimelineItem[] = [
      {
        id: 'note-1',
        kind: 'note',
        at: '2026-10-04T10:00:00Z',
        actor: { id: 1, name: 'Robin Vale' },
        summary: 'Added a note: Call back on Friday',
        link: '/clients/5',
      },
      {
        id: 'activity-2',
        kind: 'activity',
        at: '2026-10-03T10:00:00Z',
        actor: null,
        summary: 'Order SH-2026-00001 created',
        link: '/orders/7',
      },
    ]
    server.use(http.get('*/api/clients/5/timeline', () => HttpResponse.json(paginate(items))))
    render(<ClientTimeline clientId={5} enabled />)

    const list = await screen.findByRole('list', { name: 'Client activity' })
    expect(within(list).getByText(/Robin Vale/)).toBeInTheDocument()
    expect(within(list).getByRole('link', { name: 'Order SH-2026-00001 created' })).toHaveAttribute(
      'href',
      '/orders/7',
    )
  })

  it('shows an empty state', async () => {
    server.use(http.get('*/api/clients/5/timeline', () => HttpResponse.json(paginate([]))))
    render(<ClientTimeline clientId={5} enabled />)

    expect(await screen.findByText('No activity yet')).toBeInTheDocument()
  })
})
