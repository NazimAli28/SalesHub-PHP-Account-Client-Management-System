import type { Me, RoleName, User } from '@/api/types'
import type { PermissionChecker } from '@/lib/permissions'
import { ROLE_LABELS } from '@/lib/roles'
import type { EnumOption } from '@/lib/enums'
import { PRIVILEGED_ROLES, ROLE_VALUES } from './schemas'

/** Mirrors UserPolicy::canGrant: admin and support roles need `users.manage-privileged`. */
export function assignableRoles(checker: Pick<PermissionChecker, 'can'>): RoleName[] {
  const privileged = checker.can('users.manage-privileged')
  return ROLE_VALUES.filter((role) => privileged || !PRIVILEGED_ROLES.includes(role))
}

export function assignableRoleOptions(checker: Pick<PermissionChecker, 'can'>): EnumOption[] {
  return assignableRoles(checker).map((role) => ({ value: role, label: ROLE_LABELS[role] }))
}

/** Mirrors UserPolicy::canTouch: admin/support accounts need `users.manage-privileged`. */
export function canTouchUser(checker: Pick<PermissionChecker, 'can'>, target: User): boolean {
  return (
    checker.can('users.manage-privileged') ||
    !target.roles.some((role) => PRIVILEGED_ROLES.includes(role))
  )
}

export function isSelf(me: Me | null | undefined, target: User): boolean {
  return me?.id === target.id
}
