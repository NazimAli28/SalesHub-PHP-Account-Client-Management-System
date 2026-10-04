import type { ReactNode } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router'
import { ErrorState } from '@/components/layout/ErrorState'
import { FullPageSpinner } from '@/components/layout/LoadingSkeleton'
import type { PermissionRequirement } from '@/lib/permissions'
import { paths } from '@/app/paths'
import { useAuth } from './AuthProvider'
import { loginPathFor, safeRedirect } from './redirect'

/** Layout route guard: renders the child routes for signed-in users, else sends them to /login. */
export function RequireAuth({ children }: { children?: ReactNode }) {
  const { isAuthenticated, isLoading, error, refetch } = useAuth()
  const location = useLocation()

  if (isLoading) return <FullPageSpinner label="Loading your workspace" />
  if (error) {
    return (
      <div className="flex min-h-svh items-center justify-center p-6">
        <ErrorState title="We could not load your session" error={error} onRetry={refetch} />
      </div>
    )
  }
  if (!isAuthenticated) return <Navigate to={loginPathFor(location)} replace />
  return children ?? <Outlet />
}

/**
 * Route guard for permissions. With an array, any one permission is enough (scope tiers:
 * `['leads.view-all', 'leads.view-team', 'leads.view-own']`). Without access -> the 403 page.
 */
export function RequirePermission({
  permission,
  children,
}: {
  permission: PermissionRequirement
  children?: ReactNode
}) {
  const { satisfies } = useAuth()
  const location = useLocation()
  if (!satisfies(permission)) {
    return <Navigate to={paths.forbidden} replace state={{ from: location.pathname }} />
  }
  return children ?? <Outlet />
}

/** For /login: signed-in users go straight to where they were headed. */
export function RedirectIfAuthenticated({ children }: { children: ReactNode }) {
  const { isAuthenticated, isLoading } = useAuth()
  const location = useLocation()
  if (isLoading) return <FullPageSpinner label="Checking your session" />
  if (isAuthenticated) {
    const redirect = new URLSearchParams(location.search).get('redirect')
    return <Navigate to={safeRedirect(redirect)} replace />
  }
  return children
}
