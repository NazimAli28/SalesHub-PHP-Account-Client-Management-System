import { screen, waitFor } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { makeUser, paginate } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'
import type { AppNotification } from '../api'
import NotificationsPage from './NotificationsPage'

function makeNotification(id: string, overrides: Partial<AppNotification> = {}): AppNotification {
  return {
    id,
    type: 'ApprovalDecided',
    data: {
      approval_request_id: 7,
      status: 'approved',
      message: 'Your change request was approved.',
    },
    is_read: false,
    read_at: null,
    created_at: new Date().toISOString(),
    ...overrides,
  }
}

const DAY = 24 * 60 * 60 * 1000

describe('NotificationsPage', () => {
  it('groups notifications by day and links to the approval', async () => {
    server.use(
      http.get('*/api/notifications', () =>
        HttpResponse.json(
          paginate([
            makeNotification('a'),
            makeNotification('b', {
              data: {
                approval_request_id: 8,
                status: 'rejected',
                message: 'Your change request was rejected.',
              },
              created_at: new Date(Date.now() - DAY).toISOString(),
              is_read: true,
            }),
            makeNotification('c', {
              data: { message: 'Old news.' },
              created_at: new Date(Date.now() - 10 * DAY).toISOString(),
              is_read: true,
            }),
          ]),
        ),
      ),
    )
    renderWithProviders(<NotificationsPage />, { route: '/notifications', user: makeUser() })

    expect(await screen.findByRole('heading', { name: 'Today' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Yesterday' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Earlier' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Your change request was approved/ })).toHaveAttribute(
      'href',
      '/approvals/7',
    )
  })

  it('links a payment reminder to its order', async () => {
    server.use(
      http.get('*/api/notifications', () =>
        HttpResponse.json(
          paginate([
            makeNotification('p', {
              type: 'PaymentDueReminder',
              data: {
                payment_id: 3,
                order_id: 42,
                order_number: 'SH-2026-00042',
                overdue: true,
                message: 'Payment of $150.00 was due and is overdue.',
              },
            }),
          ]),
        ),
      ),
    )
    renderWithProviders(<NotificationsPage />, { route: '/notifications', user: makeUser() })

    expect(await screen.findByRole('link', { name: /is overdue/ })).toHaveAttribute(
      'href',
      '/orders/42',
    )
  })

  it('requests only unread notifications when the filter is on', async () => {
    const urls: URL[] = []
    server.use(
      http.get('*/api/notifications', ({ request }) => {
        urls.push(new URL(request.url))
        return HttpResponse.json(paginate([]))
      }),
    )
    const { user } = renderWithProviders(<NotificationsPage />, {
      route: '/notifications',
      user: makeUser(),
    })
    await screen.findByText('No notifications yet')
    await user.click(screen.getByRole('button', { name: 'Unread' }))
    expect(await screen.findByText("You're all caught up")).toBeInTheDocument()
    expect(urls.at(-1)!.searchParams.get('filter[unread]')).toBe('true')
  })

  it('marks everything as read and refreshes the bell count', async () => {
    let readAll = 0
    let counts = 0
    server.use(
      http.get('*/api/notifications', () => HttpResponse.json(paginate([makeNotification('a')]))),
      http.get('*/api/notifications/unread-count', () => {
        counts += 1
        return HttpResponse.json({ data: { unread: counts === 1 ? 3 : 0 } })
      }),
      http.post('*/api/notifications/read-all', () => {
        readAll += 1
        return HttpResponse.json({ data: { updated: 3 } })
      }),
    )
    const { user } = renderWithProviders(<NotificationsPage />, {
      route: '/notifications',
      user: makeUser(),
    })

    const button = await screen.findByRole('button', { name: 'Mark all as read' })
    await waitFor(() => expect(button).toBeEnabled())
    await user.click(button)

    expect(await screen.findByText('All notifications marked as read')).toBeInTheDocument()
    expect(readAll).toBe(1)
    await waitFor(() => expect(counts).toBeGreaterThan(1))
  })

  it('marks a single notification as read', async () => {
    let patched = ''
    server.use(
      http.get('*/api/notifications', () => HttpResponse.json(paginate([makeNotification('abc')]))),
      http.patch('*/api/notifications/:id/read', ({ params }) => {
        patched = String(params.id)
        return HttpResponse.json({ data: makeNotification('abc', { is_read: true }) })
      }),
    )
    const { user } = renderWithProviders(<NotificationsPage />, {
      route: '/notifications',
      user: makeUser(),
    })
    await user.click(await screen.findByRole('button', { name: /Mark as read/ }))
    await waitFor(() => expect(patched).toBe('abc'))
  })
})
