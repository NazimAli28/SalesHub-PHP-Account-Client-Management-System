/**
 * Permission names, mirrored from docs/architecture/auth-and-permissions.md section 3
 * (backend source: App\Support\PermissionMatrix). Typing them catches typos at compile time.
 *
 * The UI uses permissions only to show or hide things; the API re-checks every request.
 */
export const PERMISSIONS = [
  'dashboard.view',
  'reports.view-all',
  'reports.view-team',
  'reports.view-own',
  'reports.export',
  'platform-accounts.view-all',
  'platform-accounts.view-team',
  'platform-accounts.view-own',
  'platform-accounts.create',
  'platform-accounts.update',
  'platform-accounts.delete',
  'platform-accounts.assign',
  'platform-accounts.change-standing',
  'platform-accounts.reveal-credentials',
  'platform-accounts.request-new',
  'platform-accounts.request-change',
  'social-accounts.view-all',
  'social-accounts.view-team',
  'social-accounts.view-own',
  'social-accounts.create',
  'social-accounts.update',
  'social-accounts.delete',
  'social-accounts.reveal-credentials',
  'social-accounts.request-change',
  'clients.view-all',
  'clients.view-team',
  'clients.view-own',
  'clients.create',
  'clients.update',
  'clients.delete',
  'clients.request-change',
  'clients.import',
  'leads.view-all',
  'leads.view-team',
  'leads.view-own',
  'leads.create',
  'leads.update',
  'leads.delete',
  'leads.reassign',
  'leads.request-change',
  'leads.import',
  'orders.view-all',
  'orders.view-team',
  'orders.view-own',
  'orders.create',
  'orders.update',
  'orders.delete',
  'orders.request-change',
  'payments.create',
  'payments.update',
  'payments.delete',
  'payments.request-change',
  'services.view',
  'services.manage',
  'teams.view',
  'teams.manage',
  'workstations.view',
  'workstations.manage',
  'users.view',
  'users.create',
  'users.update',
  'users.deactivate',
  'users.delete',
  'users.manage-privileged',
  'approvals.view-all',
  'approvals.view-team',
  'approvals.view-own',
  'approvals.review-all',
  'approvals.review-team',
  'audit-log.view',
  'notifications.view',
] as const

export type Permission = (typeof PERMISSIONS)[number]

/** A screen is reachable with any one of these (e.g. any of the view-all/team/own tiers). */
export type PermissionRequirement = Permission | readonly Permission[]

/** Common "any scope tier" groups, so route and nav config stay short. */
export const VIEW_ANY = {
  platformAccounts: [
    'platform-accounts.view-all',
    'platform-accounts.view-team',
    'platform-accounts.view-own',
  ],
  socialAccounts: [
    'social-accounts.view-all',
    'social-accounts.view-team',
    'social-accounts.view-own',
  ],
  clients: ['clients.view-all', 'clients.view-team', 'clients.view-own'],
  leads: ['leads.view-all', 'leads.view-team', 'leads.view-own'],
  orders: ['orders.view-all', 'orders.view-team', 'orders.view-own'],
  approvals: ['approvals.view-all', 'approvals.view-team', 'approvals.view-own'],
  reviewApprovals: ['approvals.review-all', 'approvals.review-team'],
} as const satisfies Record<string, readonly Permission[]>

export interface PermissionChecker {
  can: (permission: Permission) => boolean
  canAny: (permissions: readonly Permission[]) => boolean
  canAll: (permissions: readonly Permission[]) => boolean
  /** True when the requirement is met: a single permission, or any one of a list. Undefined = public. */
  satisfies: (requirement: PermissionRequirement | undefined) => boolean
}

/** Builds the checker from the flat permission list returned by `/api/auth/me`. */
export function createPermissionChecker(
  granted: readonly string[] | null | undefined,
): PermissionChecker {
  const set = new Set(granted ?? [])
  const can = (permission: Permission) => set.has(permission)
  const canAny = (permissions: readonly Permission[]) => permissions.some(can)
  const canAll = (permissions: readonly Permission[]) => permissions.every(can)
  const satisfies = (requirement: PermissionRequirement | undefined) => {
    if (requirement === undefined) return true
    return typeof requirement === 'string' ? can(requirement) : canAny(requirement)
  }
  return { can, canAny, canAll, satisfies }
}
