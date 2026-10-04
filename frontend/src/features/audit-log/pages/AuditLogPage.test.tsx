import { screen, waitFor, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser, paginate } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import type { Activity } from '../api'
import { humanizeEvent } from '../format'
import AuditLogPage from './AuditLogPage'

function makeActivity(id: number, overrides: Partial<Activity> = {}): Activity {
  return {
    id,
    log_name: 'model',
    event: 'updated',
    description: 'updated',
    causer_id: 4,
    causer: { id: 4, name: 'Ayla Mercer', username: 'agent1' },
    subject: { type: 'platform_account', id: 12, label: 'Spark Studio' },
    properties: [],
    attribute_changes: [],
    created_at: '2026-10-01T09:00:00Z',
    ...overrides,
  }
}

const REDACTED_ENTRY = makeActivity(1, {
  attribute_changes: {
    attributes: { discord_username: 'spark_new', discord_password: '[redacted]' },
    old: { discord_username: 'spark_old', discord_password: '[redacted]' },
  } as unknown as unknown[],
})

describe('AuditLogPage', () => {
  it('humanizes event names', async () => {
    expect(humanizeEvent('security.credentials_revealed')).toBe('Credentials revealed')
    expect(humanizeEvent('login_failed')).toBe('Login failed')
    expect(humanizeEvent(null)).toBe('Activity')

    server.use(
      http.get('*/api/audit-log', () =>
        HttpResponse.json(
          paginate([
            makeActivity(1, { log_name: 'security', event: 'credentials_revealed' }),
            makeActivity(2, { log_name: 'auth', event: 'login_failed', subject: null }),
          ]),
        ),
      ),
    )
    renderWithProviders(<AuditLogPage />, { route: '/audit-log', user: makeUser() })

    expect(await screen.findByText('Credentials revealed')).toBeInTheDocument()
    expect(screen.getByText('Login failed')).toBeInTheDocument()
    expect(screen.queryByText('credentials_revealed')).not.toBeInTheDocument()
  })

  it('shows changed attributes as old and new values with secrets redacted', async () => {
    server.use(http.get('*/api/audit-log', () => HttpResponse.json(paginate([REDACTED_ENTRY]))))
    const { user } = renderWithProviders(<AuditLogPage />, {
      route: '/audit-log',
      user: makeUser(),
    })

    await screen.findByText('Spark Studio')
    await user.click(screen.getByRole('button', { name: /^View details/ }))

    const table = await screen.findByRole('table', { name: 'Changed attributes' })
    const usernameRow = within(table).getByRole('row', { name: /Discord username/ })
    expect(within(usernameRow).getByText('spark_old')).toBeInTheDocument()
    expect(within(usernameRow).getByText('spark_new')).toBeInTheDocument()

    const passwordRow = within(table).getByRole('row', { name: /Discord password/ })
    expect(within(passwordRow).getAllByText('•••• (redacted)')).toHaveLength(2)
    expect(within(table).queryByText('[redacted]')).not.toBeInTheDocument()
  })

  it('shows extra details such as role changes', async () => {
    server.use(
      http.get('*/api/audit-log', () =>
        HttpResponse.json(
          paginate([
            makeActivity(3, {
              log_name: 'user',
              event: 'role_changed',
              subject: { type: 'user', id: 7, label: 'Jo Hart' },
              properties: { from: ['sales_executive'], to: ['team_lead'] } as unknown as unknown[],
            }),
          ]),
        ),
      ),
    )
    const { user } = renderWithProviders(<AuditLogPage />, {
      route: '/audit-log',
      user: makeUser(),
    })

    await user.click(await screen.findByRole('button', { name: /^View details/ }))
    expect(await screen.findByText('No attribute changes were recorded.')).toBeInTheDocument()
    expect(screen.getByText('team_lead')).toBeInTheDocument()
  })

  it('sends the URL filters to the API and updates the URL when filters change', async () => {
    let requested: URL | undefined
    server.use(
      http.get('*/api/audit-log', ({ request }) => {
        requested = new URL(request.url)
        return HttpResponse.json(paginate([makeActivity(1)]))
      }),
    )
    const { user, router } = renderWithProviders(<AuditLogPage />, {
      route: '/audit-log?causer=4&subject_type=lead&from=2026-09-01&q=reveal',
      user: makeUser(),
    })

    await screen.findByText('Spark Studio')
    expect(Object.fromEntries(requested!.searchParams)).toMatchObject({
      sort: '-created_at',
      'filter[causer]': '4',
      'filter[subject_type]': 'lead',
      'filter[from]': '2026-09-01',
      'filter[search]': 'reveal',
    })
    expect(screen.getByLabelText('From date')).toHaveValue('2026-09-01')

    await user.click(screen.getByRole('button', { name: /^Event/ }))
    await user.click(await screen.findByRole('option', { name: 'Credentials revealed' }))
    await waitFor(() =>
      expect(router.state.location.search).toContain('event=credentials_revealed'),
    )
  })
})
