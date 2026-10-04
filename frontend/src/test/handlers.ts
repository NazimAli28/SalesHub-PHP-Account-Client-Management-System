import { http, HttpResponse } from 'msw'
import { makeLead, makeUser, paginate } from './fixtures'

export const XSRF_TOKEN = 'test-xsrf-token'

/**
 * Default API behaviour for tests. jsdom fetch responses cannot set cookies, so the CSRF
 * handler writes the XSRF-TOKEN cookie directly, as the browser would.
 */
export const handlers = [
  http.get('*/sanctum/csrf-cookie', () => {
    document.cookie = `XSRF-TOKEN=${encodeURIComponent(XSRF_TOKEN)}; path=/`
    return new HttpResponse(null, { status: 204 })
  }),
  http.get('*/api/auth/me', () =>
    HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 }),
  ),
  http.post('*/api/auth/login', () => HttpResponse.json({ data: makeUser() })),
  http.post('*/api/auth/logout', () => new HttpResponse(null, { status: 204 })),
  http.get('*/api/notifications/unread-count', () => HttpResponse.json({ data: { unread: 3 } })),
  http.get('*/api/approvals/pending-count', () =>
    HttpResponse.json({ data: { reviewable: 2, own: 0 } }),
  ),
  http.get('*/api/users', () => HttpResponse.json(paginate([]))),
  http.get('*/api/leads', () => HttpResponse.json(paginate([makeLead(1), makeLead(2)]))),
]
