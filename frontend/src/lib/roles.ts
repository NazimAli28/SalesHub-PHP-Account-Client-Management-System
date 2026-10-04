import type { RoleName } from '@/api/types'

export const ROLE_LABELS: Record<RoleName, string> = {
  admin: 'Admin',
  support: 'Support',
  team_lead: 'Team Lead',
  sales_executive: 'Sales Executive',
}

export function roleLabel(role: string): string {
  return ROLE_LABELS[role as RoleName] ?? role
}
