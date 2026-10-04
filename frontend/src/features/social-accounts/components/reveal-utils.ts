import { useMutation } from '@tanstack/react-query'
import { api } from '@/api/client'
import { errorMessage, isApiError } from '@/api/errors'
import type { Envelope } from '@/api/types'

/** Revealed secrets disappear from the screen after this long. */
export const REVEAL_HIDE_AFTER_MS = 30_000

export const REVEAL_AUDIT_NOTICE =
  'Every reveal is recorded in the audit log with your name and the time.'

export type RevealedSecrets = Record<string, string | null>

/**
 * `POST <resource>/{id}/reveal` with the chosen fields. Every call is audit-logged by the API
 * and throttled (429). Errors are shown inline by the caller (see `revealErrorMessage`), not as
 * a toast, so the user sees them next to the button they pressed.
 */
export function useRevealCredentials(path: string) {
  return useMutation({
    mutationFn: async (fields: string[]) => {
      const response = await api.post<Envelope<RevealedSecrets>>(`${path}/reveal`, { fields })
      return response.data
    },
  })
}

/** A friendly message for a failed reveal, including the 429 throttle. */
export function revealErrorMessage(error: unknown): string {
  if (isApiError(error) && error.isRateLimited) {
    return error.retryAfter
      ? `You are revealing credentials too quickly. Try again in ${error.retryAfter} seconds.`
      : 'You are revealing credentials too quickly. Wait a minute and try again.'
  }
  if (isApiError(error) && error.isForbidden) {
    return 'You do not have permission to reveal these credentials.'
  }
  return errorMessage(error)
}
