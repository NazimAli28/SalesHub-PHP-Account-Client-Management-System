import type { ComponentType } from 'react'
import type { RouteObject } from 'react-router'
import { RedirectIfAuthenticated, RequireAuth, RequirePermission } from '@/features/auth/guards'
import { FullPageSpinner } from '@/components/layout/LoadingSkeleton'
import { VIEW_ANY, type PermissionRequirement } from '@/lib/permissions'
import { AppLayout } from './layouts/AppLayout'
import { RouteErrorBoundary } from './pages/RouteErrorBoundary'
import { paths } from './paths'

/** Read by the breadcrumb, the document title and ComingSoonPage. */
export interface RouteHandle {
  /** Breadcrumb label and page title. */
  crumb: string
  /** Shown by ComingSoonPage. */
  description?: string
}

type PageModule = Promise<{ default: ComponentType }>

/**
 * A lazily loaded page behind a permission guard, with its own error boundary.
 * Each `load` becomes its own JS chunk, fetched the first time the route is visited.
 */
function page(
  path: string,
  load: () => PageModule,
  handle: RouteHandle,
  permission?: PermissionRequirement,
): RouteObject {
  const route: RouteObject = {
    path: path === paths.dashboard ? undefined : path.replace(/^\//, ''),
    index: path === paths.dashboard ? true : undefined,
    handle,
    ErrorBoundary: RouteErrorBoundary,
    lazy: async () => {
      const { default: Page } = await load()
      return {
        Component: permission
          ? () => (
              <RequirePermission permission={permission}>
                <Page />
              </RequirePermission>
            )
          : Page,
      }
    },
  }
  return route
}

const comingSoon = () => import('./pages/ComingSoonPage')

export const routes: RouteObject[] = [
  {
    path: paths.login,
    handle: { crumb: 'Sign in' } satisfies RouteHandle,
    ErrorBoundary: RouteErrorBoundary,
    HydrateFallback: FullPageSpinner,
    lazy: async () => {
      const { default: LoginPage } = await import('@/features/auth/pages/LoginPage')
      return {
        Component: () => (
          <RedirectIfAuthenticated>
            <LoginPage />
          </RedirectIfAuthenticated>
        ),
      }
    },
  },
  {
    path: '/',
    element: (
      <RequireAuth>
        <AppLayout />
      </RequireAuth>
    ),
    ErrorBoundary: RouteErrorBoundary,
    HydrateFallback: FullPageSpinner,
    children: [
      page(
        paths.dashboard,
        () => import('@/features/dashboard/pages/DashboardPage'),
        { crumb: 'Dashboard' },
        'dashboard.view',
      ),
      page(paths.profile, () => import('@/features/profile/pages/ProfilePage'), {
        crumb: 'Profile',
      }),
      page(
        paths.leads,
        () => import('@/features/leads/pages/LeadsPage'),
        { crumb: 'Leads' },
        VIEW_ANY.leads,
      ),

      // Placeholders until Phase 4. Guards match the sidebar (navigation.ts).
      page(
        paths.clients,
        comingSoon,
        { crumb: 'Clients', description: 'Client records, retention and upsell tracking.' },
        VIEW_ANY.clients,
      ),
      page(
        paths.orders,
        comingSoon,
        { crumb: 'Orders', description: 'Orders, line items and delivery status.' },
        VIEW_ANY.orders,
      ),
      page(
        paths.payments,
        comingSoon,
        { crumb: 'Payments', description: 'Scheduled and received payments.' },
        VIEW_ANY.orders,
      ),
      page(
        paths.platformAccounts,
        comingSoon,
        {
          crumb: 'Platform accounts',
          description: 'Shared platform-account inventory and standings.',
        },
        VIEW_ANY.platformAccounts,
      ),
      page(
        paths.socialAccounts,
        comingSoon,
        { crumb: 'Social accounts', description: 'Social profiles linked to platform accounts.' },
        VIEW_ANY.socialAccounts,
      ),
      page(
        paths.approvals,
        comingSoon,
        { crumb: 'Approvals', description: 'Maker-checker review queue.' },
        VIEW_ANY.approvals,
      ),
      page(
        paths.notifications,
        comingSoon,
        { crumb: 'Notifications', description: 'Everything that needs your attention.' },
        'notifications.view',
      ),
      page(
        paths.users,
        comingSoon,
        { crumb: 'Users', description: 'Team members, roles and access.' },
        'users.view',
      ),
      page(
        paths.teams,
        comingSoon,
        { crumb: 'Teams', description: 'Sales teams, floors and shifts.' },
        'teams.view',
      ),
      page(
        paths.workstations,
        comingSoon,
        { crumb: 'Workstations', description: 'Office PCs and their assigned accounts.' },
        'workstations.view',
      ),
      page(
        paths.services,
        comingSoon,
        { crumb: 'Services', description: 'The design-services catalogue and base prices.' },
        'services.view',
      ),
      page(
        paths.auditLog,
        comingSoon,
        { crumb: 'Audit log', description: 'Who changed what, and when.' },
        'audit-log.view',
      ),

      page(paths.forbidden, () => import('./pages/ForbiddenPage'), { crumb: 'Access denied' }),
      page('*', () => import('./pages/NotFoundPage'), { crumb: 'Page not found' }),
    ],
  },
]
