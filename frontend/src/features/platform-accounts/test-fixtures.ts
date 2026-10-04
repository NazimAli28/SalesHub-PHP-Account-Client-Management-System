import { makeSocialAccount } from '@/features/social-accounts/test-fixtures'
import type { PlatformAccount } from './types'

/** Fictional platform accounts for tests. */
export function makePlatformAccount(
  id: number,
  overrides: Partial<PlatformAccount> = {},
): PlatformAccount {
  return {
    id,
    email: `vale.accounts${id}@example.com`,
    discord_email: `discord${id}@example.com`,
    discord_username: `vale_accounts_${id}`,
    discord_created_on: '2025-11-02',
    recovery_email: `recovery${id}@example.com`,
    batch_date: '2026-09-20',
    standing: { value: 'active', label: 'Active' },
    standing_changed_at: '2026-09-21T08:00:00Z',
    notes: 'Warm-up finished.',
    has_email_password: true,
    has_discord_password: true,
    has_recovery_phone: false,
    has_phone_holder_name: false,
    workstation_id: 3,
    assigned_at: '2026-09-22T09:00:00Z',
    workstation: {
      id: 3,
      code: 'WS-03',
      label: 'Window seat',
      team_id: 2,
      team: { id: 2, name: 'Unit 1 Alpha' },
    },
    social_accounts_count: 1,
    pending_change: null,
    created_at: '2026-09-20T08:00:00Z',
    updated_at: '2026-09-22T09:00:00Z',
    ...overrides,
  }
}

export { makeSocialAccount }
