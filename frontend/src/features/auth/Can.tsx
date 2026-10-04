import type { ReactNode } from 'react'
import type { Permission } from '@/lib/permissions'
import { useAuth } from './AuthProvider'

type CanProps = {
  children: ReactNode
  /** Rendered when the check fails. Defaults to nothing. */
  fallback?: ReactNode
} & (
  | { permission: Permission; anyOf?: never; allOf?: never }
  | { anyOf: readonly Permission[]; permission?: never; allOf?: never }
  | { allOf: readonly Permission[]; permission?: never; anyOf?: never }
)

/**
 * Shows its children only when the user has the permission(s).
 *
 *   <Can permission="leads.create"><Button>New lead</Button></Can>
 *   <Can anyOf={['leads.update', 'leads.request-change']}>...</Can>
 */
export function Can({ children, fallback = null, ...check }: CanProps) {
  const { can, canAny, canAll } = useAuth()
  const allowed = check.permission
    ? can(check.permission)
    : check.anyOf
      ? canAny(check.anyOf)
      : canAll(check.allOf ?? [])
  return <>{allowed ? children : fallback}</>
}
