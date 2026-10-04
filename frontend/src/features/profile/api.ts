import { queryOptions, useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { Envelope, IsoDateTime } from '@/api/types'

/**
 * Query keys for the signed-in user's security data. Deliberately not under `['auth', ...]`:
 * `resetSession` keeps only that prefix, and these must be dropped when the user changes.
 */
export const securityKeys = {
  all: ['account-security'] as const,
  sessions: () => [...securityKeys.all, 'sessions'] as const,
}

export interface TwoFactorSetup {
  /** Base32 secret, for typing into the app when the QR code cannot be scanned. */
  secret: string
  otpauth_url: string
  /** `data:image/svg+xml;base64,...` */
  qr_code: string
}

export interface RecoveryCodes {
  recovery_codes: string[]
}

export interface BrowserSession {
  /** Opaque (a keyed hash of the session id). */
  id: string
  ip_address: string | null
  /** Short label such as "Chrome on Windows". */
  device: string
  last_active_at: IsoDateTime
  is_current: boolean
}

export async function startTwoFactorSetup(): Promise<TwoFactorSetup> {
  return (await api.post<Envelope<TwoFactorSetup>>('/auth/two-factor')).data
}

export async function confirmTwoFactor(payload: { code: string }): Promise<RecoveryCodes> {
  return (await api.post<Envelope<RecoveryCodes>>('/auth/two-factor/confirm', payload)).data
}

export function disableTwoFactor(payload: { password: string }): Promise<void> {
  return api.delete<void>('/auth/two-factor', { body: payload })
}

/** POST (not GET) because the password travels in the body. */
export async function showRecoveryCodes(payload: { password: string }): Promise<RecoveryCodes> {
  return (await api.post<Envelope<RecoveryCodes>>('/auth/two-factor/recovery-codes/view', payload))
    .data
}

export async function regenerateRecoveryCodes(payload: {
  password: string
}): Promise<RecoveryCodes> {
  return (await api.post<Envelope<RecoveryCodes>>('/auth/two-factor/recovery-codes', payload)).data
}

export async function fetchSessions(): Promise<BrowserSession[]> {
  return (await api.get<Envelope<BrowserSession[]>>('/auth/sessions')).data
}

export async function signOutOtherSessions(payload: {
  password: string
}): Promise<{ revoked: number }> {
  return (
    await api.delete<Envelope<{ revoked: number }>>('/auth/sessions/others', { body: payload })
  ).data
}

export function useSessionsQuery() {
  return useQuery(queryOptions({ queryKey: securityKeys.sessions(), queryFn: fetchSessions }))
}
