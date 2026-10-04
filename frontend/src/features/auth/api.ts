import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
  type QueryClient,
} from '@tanstack/react-query'
import { api, ensureCsrfCookie } from '@/api/client'
import { isApiError } from '@/api/errors'
import type { Envelope, Me } from '@/api/types'

export const authKeys = {
  me: ['auth', 'me'] as const,
}

/**
 * Switches the cached session to `me` (a user, or `null` for signed out) and drops every other
 * cached query, so nothing from a previous user leaks into the next session. The /me query
 * itself is updated in place rather than removed: removing it would detach the mounted
 * AuthProvider observer from the cache.
 */
export function resetSession(queryClient: QueryClient, me: Me | null): void {
  queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== authKeys.me[0] })
  queryClient.setQueryData(authKeys.me, me)
}

export interface LoginPayload {
  login: string
  password: string
  remember: boolean
}

export interface ChangePasswordPayload {
  current_password: string
  password: string
  password_confirmation: string
}

/** `null` means "signed out" (a 401 is an answer here, not an error). */
export async function fetchMe(): Promise<Me | null> {
  try {
    const response = await api.get<Envelope<Me>>('/auth/me', { skipUnauthorizedHandler: true })
    return response.data
  } catch (error) {
    if (isApiError(error) && error.status === 401) return null
    throw error
  }
}

export const meQueryOptions = queryOptions({
  queryKey: authKeys.me,
  queryFn: fetchMe,
  staleTime: 5 * 60_000,
  retry: false,
})

export function useMeQuery() {
  return useQuery(meQueryOptions)
}

/**
 * Fields `MeResource` gained with two-factor sign-in. Optional here until the OpenAPI types are
 * regenerated; read them through `accountSecurity()`.
 */
export interface AccountSecurityFields {
  two_factor_enabled?: boolean
  /** Public demo: shared accounts, so password changes and two-factor setup are switched off. */
  demo_mode?: boolean
}

export function accountSecurity(user: Me): { twoFactorEnabled: boolean; demoMode: boolean } {
  const fields = user as Me & AccountSecurityFields
  return {
    twoFactorEnabled: fields.two_factor_enabled === true,
    demoMode: fields.demo_mode === true,
  }
}

/** Login either signs in directly or asks for the second factor first. */
export type LoginResult = { twoFactor: false; me: Me } | { twoFactor: true }

export async function login(payload: LoginPayload): Promise<LoginResult> {
  // Fresh CSRF cookie before login: the session is regenerated on sign-in.
  await ensureCsrfCookie(true)
  const response = await api.post<Envelope<Me | { two_factor: true }>>('/auth/login', payload, {
    skipUnauthorizedHandler: true,
  })
  if ('two_factor' in response.data && response.data.two_factor === true) {
    return { twoFactor: true }
  }
  return { twoFactor: false, me: response.data as Me }
}

export function useLogin() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: login,
    onSuccess: (result) => {
      if (!result.twoFactor) resetSession(queryClient, result.me)
    },
  })
}

/** Either the 6-digit authenticator code or one recovery code. */
export type TwoFactorChallengePayload = { code: string } | { recovery_code: string }

export async function twoFactorChallenge(payload: TwoFactorChallengePayload): Promise<Me> {
  const response = await api.post<Envelope<Me>>('/auth/two-factor-challenge', payload, {
    skipUnauthorizedHandler: true,
  })
  return response.data
}

export function useTwoFactorChallenge() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: twoFactorChallenge,
    onSuccess: (me) => resetSession(queryClient, me),
  })
}

export function useLogout() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => api.post<void>('/auth/logout', undefined, { skipUnauthorizedHandler: true }),
    // Sign out locally even if the server call fails (e.g. the session had already expired).
    onSettled: () => resetSession(queryClient, null),
  })
}

export function changePassword(payload: ChangePasswordPayload): Promise<void> {
  return api.put<void>('/auth/password', payload)
}
