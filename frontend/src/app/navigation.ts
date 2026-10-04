import {
  BellIcon,
  BriefcaseBusinessIcon,
  ClipboardCheckIcon,
  CreditCardIcon,
  HistoryIcon,
  KeyRoundIcon,
  LayoutDashboardIcon,
  MonitorIcon,
  PackageIcon,
  ShareIcon,
  TagsIcon,
  TargetIcon,
  UserCogIcon,
  UsersIcon,
  type LucideIcon,
} from 'lucide-react'
import { VIEW_ANY, type PermissionChecker, type PermissionRequirement } from '@/lib/permissions'
import { paths } from './paths'

export interface NavItem {
  label: string
  path: string
  icon: LucideIcon
  /** Hidden unless the user satisfies this (one permission, or any one of a list). */
  permission?: PermissionRequirement
  /** Shows the pending-approvals count next to the item. */
  badge?: 'pendingApprovals'
}

export interface NavSection {
  label: string
  items: NavItem[]
}

/**
 * The single source of truth for the sidebar. Route guards use the same permission lists
 * (see router.tsx), so a visible link never leads to a 403.
 */
export const NAVIGATION: NavSection[] = [
  {
    label: 'Overview',
    items: [
      {
        label: 'Dashboard',
        path: paths.dashboard,
        icon: LayoutDashboardIcon,
        permission: 'dashboard.view',
      },
      {
        label: 'Approvals',
        path: paths.approvals,
        icon: ClipboardCheckIcon,
        permission: VIEW_ANY.approvals,
        badge: 'pendingApprovals',
      },
      {
        label: 'Notifications',
        path: paths.notifications,
        icon: BellIcon,
        permission: 'notifications.view',
      },
    ],
  },
  {
    label: 'Sales',
    items: [
      { label: 'Leads', path: paths.leads, icon: TargetIcon, permission: VIEW_ANY.leads },
      {
        label: 'Clients',
        path: paths.clients,
        icon: BriefcaseBusinessIcon,
        permission: VIEW_ANY.clients,
      },
      { label: 'Orders', path: paths.orders, icon: PackageIcon, permission: VIEW_ANY.orders },
      {
        label: 'Payments',
        path: paths.payments,
        icon: CreditCardIcon,
        permission: VIEW_ANY.orders,
      },
    ],
  },
  {
    label: 'Accounts',
    items: [
      {
        label: 'Platform accounts',
        path: paths.platformAccounts,
        icon: KeyRoundIcon,
        permission: VIEW_ANY.platformAccounts,
      },
      {
        label: 'Social accounts',
        path: paths.socialAccounts,
        icon: ShareIcon,
        permission: VIEW_ANY.socialAccounts,
      },
    ],
  },
  {
    label: 'Administration',
    items: [
      { label: 'Users', path: paths.users, icon: UsersIcon, permission: 'users.view' },
      { label: 'Teams', path: paths.teams, icon: UserCogIcon, permission: 'teams.view' },
      {
        label: 'Workstations',
        path: paths.workstations,
        icon: MonitorIcon,
        permission: 'workstations.view',
      },
      { label: 'Services', path: paths.services, icon: TagsIcon, permission: 'services.view' },
      { label: 'Audit log', path: paths.auditLog, icon: HistoryIcon, permission: 'audit-log.view' },
    ],
  },
]

/** Removes items the user cannot open, then sections left empty. */
export function filterNavigation(
  sections: readonly NavSection[],
  satisfies: PermissionChecker['satisfies'],
): NavSection[] {
  return sections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => satisfies(item.permission)),
    }))
    .filter((section) => section.items.length > 0)
}

/** Finds the nav item for a pathname (used for the breadcrumb). Longest match wins. */
export function findNavItem(pathname: string): NavItem | undefined {
  const items = NAVIGATION.flatMap((section) => section.items)
  return items
    .filter((item) =>
      item.path === '/'
        ? pathname === '/'
        : pathname === item.path || pathname.startsWith(`${item.path}/`),
    )
    .sort((a, b) => b.path.length - a.path.length)[0]
}
