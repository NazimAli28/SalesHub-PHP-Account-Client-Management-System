import { createContext, use, useMemo, type ReactNode } from 'react'
import type { Me } from '@/api/types'
import { createPermissionChecker, type PermissionChecker } from '@/lib/permissions'
import { useMeQuery } from './api'

export interface AuthContextValue extends PermissionChecker {
  /** The signed-in user, `null` when signed out, `undefined` while the first check runs. */
  user: Me | null | undefined
  isAuthenticated: boolean
  /** True only during the very first `/auth/me` request. */
  isLoading: boolean
  /** Set when `/auth/me` failed for a reason other than 401 (e.g. the API is down). */
  error: Error | null
  refetch: () => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

/**
 * Auth state is just the `/auth/me` query: TanStack Query owns caching and refetching, and login,
 * logout and the global 401 handler update that one cache entry.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const { data: user, isPending, error, refetch } = useMeQuery()

  const value = useMemo<AuthContextValue>(
    () => ({
      ...createPermissionChecker(user?.permissions),
      user,
      isAuthenticated: Boolean(user),
      isLoading: isPending,
      error,
      refetch: () => void refetch(),
    }),
    [user, isPending, error, refetch],
  )

  return <AuthContext value={value}>{children}</AuthContext>
}

// eslint-disable-next-line react/only-export-components -- hook lives next to its provider
export function useAuth(): AuthContextValue {
  const context = use(AuthContext)
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>')
  return context
}
