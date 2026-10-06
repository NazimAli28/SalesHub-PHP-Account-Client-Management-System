/** Sign-in, session and profile endpoints (routes/api.php, `auth` prefix). */
import { http, HttpResponse } from 'msw'
import { isoNow } from '../dates'
import { logActivity } from '../engine'
import { demoMode, envelope, json, noContent, route, validationError } from '../http'
import { permissionsOf } from '../permissions'
import { presentUser } from '../present'
import { currentUser, db, setSessionUser } from '../store'
import type { UserRow } from '../types'
import { checkPassword } from './passwords'

export const DEMO_XSRF_TOKEN = 'static-demo-xsrf-token'

export function meBody(user: UserRow) {
  return {
    ...presentUser(user),
    permissions: [...permissionsOf(user)].sort(),
    two_factor_enabled: false,
    demo_mode: true,
  }
}

const BAD_CREDENTIALS = 'These credentials do not match our records.'

export const authHandlers = [
  // Sanctum's CSRF cookie. The SPA copies it into X-XSRF-TOKEN; the demo accepts any value.
  http.get('*/sanctum/csrf-cookie', () => {
    document.cookie = `XSRF-TOKEN=${encodeURIComponent(DEMO_XSRF_TOKEN)}; path=/; SameSite=Lax`
    return new HttpResponse(null, { status: 204 })
  }),

  route(
    'post',
    '/auth/login',
    async ({ body }) => {
      const data = await body()
      const login = typeof data.login === 'string' ? data.login.trim().toLowerCase() : ''
      const password = typeof data.password === 'string' ? data.password : ''
      const errors: Record<string, string[]> = {}
      if (!login) errors.login = ['The login field is required.']
      if (!password) errors.password = ['The password field is required.']
      if (Object.keys(errors).length > 0) throw validationError(errors)

      const user = db().users.find((u) =>
        login.includes('@') ? u.email.toLowerCase() === login : u.username.toLowerCase() === login,
      )
      if (!user || !checkPassword(user, password)) {
        throw validationError({ login: [BAD_CREDENTIALS] })
      }
      if (!user.is_active) {
        return json(
          {
            message: 'This account has been deactivated. Contact an administrator.',
            code: 'account_inactive',
          },
          403,
        )
      }

      setSessionUser(user.id)
      user.last_login_at = isoNow()
      logActivity({
        logName: 'auth',
        event: 'login',
        causer: user,
        subject: { type: 'user', id: user.id, label: user.username },
        properties: { ip: '203.0.113.x', user_agent: navigator.userAgent },
      })
      return envelope(meBody(user))
    },
    { auth: false },
  ),

  route(
    'post',
    '/auth/two-factor-challenge',
    () => {
      throw validationError({ code: ['Two-factor sign-in is not enabled for the demo accounts.'] })
    },
    { auth: false },
  ),

  route('post', '/auth/logout', ({ user }) => {
    logActivity({
      logName: 'auth',
      event: 'logout',
      causer: user,
      subject: { type: 'user', id: user.id, label: user.username },
    })
    setSessionUser(null)
    return noContent()
  }),

  route('get', '/auth/me', () => {
    const user = currentUser()
    return envelope(meBody(user!))
  }),

  route('put', '/auth/password', () => {
    throw demoMode('changing the password is disabled')
  }),

  route('post', '/auth/two-factor', () => {
    throw demoMode('two-factor sign-in is disabled')
  }),
  route('post', '/auth/two-factor/confirm', () => {
    throw demoMode('two-factor sign-in is disabled')
  }),
  route('delete', '/auth/two-factor', () => {
    throw demoMode('two-factor sign-in is disabled')
  }),
  route('post', '/auth/two-factor/recovery-codes/view', () =>
    json({ message: 'Two-factor sign-in is not enabled.', code: 'two_factor_not_enabled' }, 409),
  ),
  route('post', '/auth/two-factor/recovery-codes', () =>
    json({ message: 'Two-factor sign-in is not enabled.', code: 'two_factor_not_enabled' }, 409),
  ),

  route('get', '/auth/sessions', () =>
    envelope([
      {
        id: 'demo-browser-session',
        ip_address: '203.0.113.x',
        device: describeBrowser(navigator.userAgent),
        last_active_at: isoNow(),
        is_current: true,
      },
    ]),
  ),
  route('delete', '/auth/sessions/others', () => {
    throw demoMode('signing out the other sessions is disabled')
  }),
]

function describeBrowser(agent: string): string {
  const browser = /Edg\//.test(agent)
    ? 'Edge'
    : /Firefox\//.test(agent)
      ? 'Firefox'
      : /Chrome\//.test(agent)
        ? 'Chrome'
        : /Safari\//.test(agent)
          ? 'Safari'
          : 'Browser'
  const os = /Windows/.test(agent)
    ? 'Windows'
    : /Mac OS X/.test(agent)
      ? 'macOS'
      : /Android/.test(agent)
        ? 'Android'
        : /iPhone|iPad/.test(agent)
          ? 'iOS'
          : /Linux/.test(agent)
            ? 'Linux'
            : 'Unknown OS'
  return `${browser} on ${os}`
}
