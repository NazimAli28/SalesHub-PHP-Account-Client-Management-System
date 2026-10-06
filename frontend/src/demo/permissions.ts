/**
 * Permissions, row scoping and policies of the browser demo. Mirrors the backend: PermissionMatrix
 * (exported per role), HasVisibilityScope (`visibleTo`) per model and the policy classes.
 */
import { config, db, find } from './store'
import type {
  ApprovalRow,
  ClientRow,
  LeadRow,
  OrderRow,
  PaymentRow,
  PlatformAccountRow,
  SocialAccountRow,
  UserRow,
} from './types'

export type Tier = 'all' | 'team' | 'own' | 'none'

export function permissionsOf(user: UserRow): string[] {
  const role = user.roles[0] ?? ''
  return config().rolePermissions[role] ?? []
}

export function can(user: UserRow, permission: string): boolean {
  return permissionsOf(user).includes(permission)
}

export function hasRole(user: UserRow, ...roles: string[]): boolean {
  return user.roles.some((role) => roles.includes(role))
}

export function tier(user: UserRow, resource: string): Tier {
  if (can(user, `${resource}.view-all`)) return 'all'
  if (can(user, `${resource}.view-team`)) return user.team_id === null ? 'none' : 'team'
  if (can(user, `${resource}.view-own`)) return 'own'
  return 'none'
}

export function canViewAny(user: UserRow, resource: string): boolean {
  return ['view-all', 'view-team', 'view-own'].some((scope) => can(user, `${resource}.${scope}`))
}

function teamOf(userId: number | null | undefined): number | null {
  return find(db().users, userId)?.team_id ?? null
}

// ---------------------------------------------------------------------------
// visibleTo($user), per model
// ---------------------------------------------------------------------------

export function leadVisible(user: UserRow, lead: LeadRow): boolean {
  switch (tier(user, 'leads')) {
    case 'all':
      return true
    case 'team':
      return teamOf(lead.owner_id) === user.team_id
    case 'own':
      return lead.owner_id === user.id
    default:
      return false
  }
}

export function clientVisible(user: UserRow, client: ClientRow): boolean {
  const scope = tier(user, 'clients')
  if (scope === 'all') return true
  if (scope === 'none') return false
  const matches = (ownerId: number | null) =>
    scope === 'team' ? ownerId !== null && teamOf(ownerId) === user.team_id : ownerId === user.id
  if (matches(client.owner_id)) return true
  const { leads, orders } = db()
  return (
    leads.some((lead) => lead.client_id === client.id && matches(lead.owner_id)) ||
    orders.some((order) => order.client_id === client.id && matches(order.owner_id))
  )
}

export function orderVisible(user: UserRow, order: OrderRow): boolean {
  switch (tier(user, 'orders')) {
    case 'all':
      return true
    case 'team':
      return order.team_id === user.team_id || teamOf(order.owner_id) === user.team_id
    case 'own':
      return order.owner_id === user.id
    default:
      return false
  }
}

export function paymentVisible(user: UserRow, payment: PaymentRow): boolean {
  const scope = tier(user, 'orders')
  if (scope === 'all') return true
  if (scope === 'none') return false
  const order = find(db().orders, payment.order_id)
  return order !== undefined && orderVisible(user, order)
}

export function platformAccountVisible(user: UserRow, account: PlatformAccountRow): boolean {
  switch (tier(user, 'platform-accounts')) {
    case 'all':
      return true
    case 'team': {
      const station = find(db().workstations, account.workstation_id)
      return station !== undefined && station.team_id === user.team_id
    }
    case 'own':
      return user.workstation_id !== null && account.workstation_id === user.workstation_id
    default:
      return false
  }
}

export function socialAccountVisible(user: UserRow, account: SocialAccountRow): boolean {
  const scope = tier(user, 'social-accounts')
  if (scope === 'all') return true
  if (scope === 'none') return false
  const parent = find(db().platform_accounts, account.platform_account_id)
  return parent !== undefined && platformAccountVisible(user, parent)
}

export function approvalVisible(user: UserRow, approval: ApprovalRow): boolean {
  switch (tier(user, 'approvals')) {
    case 'all':
      return true
    case 'team':
      return teamOf(approval.requested_by_id) === user.team_id
    case 'own':
      return approval.requested_by_id === user.id
    default:
      return false
  }
}

/** Users: all with users.view + users.update, the own team with users.view, else only yourself. */
export function userVisible(user: UserRow, target: UserRow): boolean {
  if (can(user, 'users.view') && can(user, 'users.update')) return true
  if (can(user, 'users.view')) return user.team_id !== null && target.team_id === user.team_id
  return target.id === user.id
}

// ---------------------------------------------------------------------------
// Module descriptors used by the generic write rule (update vs. queue vs. 403)
// ---------------------------------------------------------------------------

export type ApprovableType =
  'lead' | 'client' | 'order' | 'payment' | 'platform_account' | 'social_account'

/** Permission prefix of each approvable type. */
export const PERMISSION_PREFIX: Record<ApprovableType, string> = {
  lead: 'leads',
  client: 'clients',
  order: 'orders',
  payment: 'payments',
  platform_account: 'platform-accounts',
  social_account: 'social-accounts',
}

export function visibleRecord(user: UserRow, type: ApprovableType, record: unknown): boolean {
  switch (type) {
    case 'lead':
      return leadVisible(user, record as LeadRow)
    case 'client':
      return clientVisible(user, record as ClientRow)
    case 'order':
      return orderVisible(user, record as OrderRow)
    case 'payment':
      return paymentVisible(user, record as PaymentRow)
    case 'platform_account':
      return platformAccountVisible(user, record as PlatformAccountRow)
    case 'social_account':
      return socialAccountVisible(user, record as SocialAccountRow)
  }
}

/** `canOn`: the permission and the record in the user's scope. */
export function canOn(
  user: UserRow,
  ability: 'update' | 'delete' | 'request-change',
  type: ApprovableType,
  record: unknown,
): boolean {
  if (!can(user, `${PERMISSION_PREFIX[type]}.${ability}`) || !visibleRecord(user, type, record)) {
    return false
  }
  // PaymentPolicy::update: a paid payment only by admin or support.
  if (type === 'payment' && ability === 'update') {
    const payment = record as PaymentRow
    return payment.status.value !== 'paid' || hasRole(user, 'admin', 'support')
  }
  return true
}

export function canView(user: UserRow, type: ApprovableType, record: unknown): boolean {
  const resource = type === 'payment' ? 'orders' : PERMISSION_PREFIX[type]
  return canViewAny(user, resource) && visibleRecord(user, type, record)
}

// ---------------------------------------------------------------------------
// Approvals
// ---------------------------------------------------------------------------

const TEAM_REVIEWABLE = ['lead', 'client', 'order', 'payment']

export function canReview(user: UserRow, approval: ApprovalRow): boolean {
  if (approval.requested_by_id === user.id) return false
  if (can(user, 'approvals.review-all')) return true
  if (!can(user, 'approvals.review-team') || user.team_id === null) return false
  return (
    TEAM_REVIEWABLE.includes(approval.approvable?.type ?? '') &&
    teamOf(approval.requested_by_id) === user.team_id
  )
}

export function reviewable(user: UserRow, approval: ApprovalRow): boolean {
  return approval.status.value === 'pending' && canReview(user, approval)
}

/** ReviewerResolver: who is notified about a new request. */
export function reviewersFor(approval: ApprovalRow): UserRow[] {
  const requesterTeam = teamOf(approval.requested_by_id)
  return db().users.filter((user) => {
    if (!user.is_active || user.id === approval.requested_by_id) return false
    if (can(user, 'approvals.review-all')) return true
    return (
      requesterTeam !== null &&
      TEAM_REVIEWABLE.includes(approval.approvable?.type ?? '') &&
      can(user, 'approvals.review-team') &&
      user.team_id === requesterTeam
    )
  })
}

// ---------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------

function isLastActiveAdmin(target: UserRow): boolean {
  if (!target.is_active || !hasRole(target, 'admin')) return false
  return !db().users.some((u) => u.id !== target.id && u.is_active && hasRole(u, 'admin'))
}

function canTouch(user: UserRow, target: UserRow): boolean {
  return !hasRole(target, 'admin', 'support') || can(user, 'users.manage-privileged')
}

export function canManageUser(
  user: UserRow,
  permission: 'users.update' | 'users.deactivate' | 'users.delete',
  target: UserRow,
): boolean {
  const base = can(user, permission) && userVisible(user, target) && canTouch(user, target)
  if (permission === 'users.update') return base
  return base && user.id !== target.id && !isLastActiveAdmin(target)
}

export function canAssignRole(user: UserRow, target: UserRow | null, role: string): boolean {
  if (!can(user, 'users.update') && !can(user, 'users.create')) return false
  if (target && !canTouch(user, target)) return false
  if (['admin', 'support'].includes(role) && !can(user, 'users.manage-privileged')) return false
  return role === 'admin' || target === null || !isLastActiveAdmin(target)
}

export function isDemoAccount(user: UserRow): boolean {
  return config().demoUsernames.includes(user.username.toLowerCase())
}
