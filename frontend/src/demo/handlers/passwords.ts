/**
 * Sign-in passwords of the browser demo. The seeded accounts share the published demo password;
 * users created during the visit keep the password they were given (this tab only).
 */
import type { UserRow } from '../types'

export const DEMO_PASSWORD = 'Demo@12345'
const KEY = 'saleshub.static-demo.passwords'

function created(): Record<string, string> {
  try {
    return JSON.parse(window.sessionStorage.getItem(KEY) ?? '{}') as Record<string, string>
  } catch {
    return {}
  }
}

export function rememberPassword(userId: number, password: string): void {
  try {
    window.sessionStorage.setItem(KEY, JSON.stringify({ ...created(), [userId]: password }))
  } catch {
    // Blocked storage: the new user can't sign in after a refresh, nothing else breaks.
  }
}

export function checkPassword(user: UserRow, password: string): boolean {
  return (created()[user.id] ?? DEMO_PASSWORD) === password
}
