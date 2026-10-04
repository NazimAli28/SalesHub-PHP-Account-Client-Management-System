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

      page(
        paths.today,
        () => import('@/features/today/pages/TodayPage'),
        { crumb: 'Today' },
        'dashboard.view',
      ),

      // Sales
      page(
        paths.clients,
        () => import('@/features/clients/pages/ClientsPage'),
        { crumb: 'Clients' },
        VIEW_ANY.clients,
      ),
      page(
        `${paths.clients}/:clientId`,
        () => import('@/features/clients/pages/ClientDetailPage'),
        { crumb: 'Client' },
        VIEW_ANY.clients,
      ),
      page(
        paths.orders,
        () => import('@/features/orders/pages/OrdersPage'),
        { crumb: 'Orders' },
        VIEW_ANY.orders,
      ),
      page(
        `${paths.orders}/:orderId`,
        () => import('@/features/orders/pages/OrderDetailPage'),
        { crumb: 'Order' },
        VIEW_ANY.orders,
      ),
      page(
        paths.payments,
        () => import('@/features/payments/pages/PaymentsPage'),
        { crumb: 'Payments' },
        VIEW_ANY.orders,
      ),

      // Accounts
      page(
        paths.platformAccounts,
        () => import('@/features/platform-accounts/pages/PlatformAccountsPage'),
        { crumb: 'Platform accounts' },
        VIEW_ANY.platformAccounts,
      ),
      page(
        `${paths.platformAccounts}/:platformAccountId`,
        () => import('@/features/platform-accounts/pages/PlatformAccountDetailPage'),
        { crumb: 'Platform account' },
        VIEW_ANY.platformAccounts,
      ),
      page(
        paths.socialAccounts,
        () => import('@/features/social-accounts/pages/SocialAccountsPage'),
        { crumb: 'Social accounts' },
        VIEW_ANY.socialAccounts,
      ),

      // Workflow
      page(
        paths.approvals,
        () => import('@/features/approvals/pages/ApprovalsPage'),
        { crumb: 'Approvals' },
        VIEW_ANY.approvals,
      ),
      page(
        `${paths.approvals}/:approvalId`,
        () => import('@/features/approvals/pages/ApprovalDetailPage'),
        { crumb: 'Approval' },
        VIEW_ANY.approvals,
      ),
      page(
        paths.notifications,
        () => import('@/features/notifications/pages/NotificationsPage'),
        { crumb: 'Notifications' },
        'notifications.view',
      ),

      // Administration
      page(
        paths.users,
        () => import('@/features/users/pages/UsersPage'),
        { crumb: 'Users' },
        'users.view',
      ),
      page(
        paths.teams,
        () => import('@/features/teams/pages/TeamsPage'),
        { crumb: 'Teams' },
        'teams.view',
      ),
      page(
        paths.workstations,
        () => import('@/features/workstations/pages/WorkstationsPage'),
        { crumb: 'Workstations' },
        'workstations.view',
      ),
      page(
        paths.services,
        () => import('@/features/services/pages/ServicesPage'),
        { crumb: 'Services' },
        'services.view',
      ),
      page(
        paths.auditLog,
        () => import('@/features/audit-log/pages/AuditLogPage'),
        { crumb: 'Audit log' },
        'audit-log.view',
      ),

      page(paths.forbidden, () => import('./pages/ForbiddenPage'), { crumb: 'Access denied' }),
      page('*', () => import('./pages/NotFoundPage'), { crumb: 'Page not found' }),
    ],
  },
]
