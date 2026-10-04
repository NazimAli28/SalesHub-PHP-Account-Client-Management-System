import type { Lead, Me, Paginated } from '@/api/types'
import { PERMISSIONS, type Permission } from '@/lib/permissions'

/** Fictional users only. */
export function makeUser(
  overrides: Partial<Me> = {},
  permissions: readonly Permission[] = PERMISSIONS,
): Me {
  return {
    id: 1,
    name: 'Robin Vale',
    username: 'admin',
    email: 'admin@example.com',
    avatar_url: null,
    is_active: true,
    team_id: 1,
    workstation_id: null,
    last_login_at: '2026-10-01T09:00:00Z',
    roles: ['admin'],
    team: { id: 1, name: 'Unit 1 Alpha', floor: 3, shift: 'evening' },
    workstation: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    permissions: [...permissions],
    ...overrides,
  }
}

/** A sales executive: own-scope permissions (a subset of the real matrix). */
export const SALES_EXECUTIVE_PERMISSIONS: Permission[] = [
  'dashboard.view',
  'leads.view-own',
  'leads.create',
  'leads.request-change',
  'clients.view-own',
  'clients.create',
  'orders.view-own',
  'orders.create',
  'platform-accounts.view-own',
  'social-accounts.view-own',
  'services.view',
  'teams.view',
  'workstations.view',
  'approvals.view-own',
  'notifications.view',
]

export function makeLead(id: number, overrides: Partial<Lead> = {}): Lead {
  return {
    id,
    stage: { value: 'new', label: 'New' },
    stage_changed_at: '2026-10-01T10:00:00Z',
    contacted_on: '2026-09-30',
    estimated_value: { amount_cents: 25000, currency: 'USD', formatted: '$250.00' },
    last_message: 'Asked about an emote pack.',
    next_follow_up_on: null,
    lost_reason: null,
    lost_note: null,
    client_id: id + 100,
    owner_id: 4,
    closer_id: null,
    platform_account_id: null,
    order_id: null,
    client: {
      id: id + 100,
      discord_username: `streamer${id}`,
      name: `Streamer ${id}`,
      status: null,
    },
    owner: { id: 4, name: 'Ayla Mercer', username: 'agent1' },
    pending_change: null,
    created_at: '2026-09-30T10:00:00Z',
    updated_at: '2026-10-01T10:00:00Z',
    ...overrides,
  } as Lead
}

export function paginate<T>(
  data: T[],
  { page = 1, perPage = 25, total = data.length } = {},
): Paginated<T> {
  const from = total === 0 ? null : (page - 1) * perPage + 1
  return {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: page,
      from,
      to: from === null ? null : from + data.length - 1,
      last_page: Math.max(1, Math.ceil(total / perPage)),
      per_page: perPage,
      total,
      path: '/api/leads',
    },
  }
}
