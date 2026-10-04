import { paginate } from '@/test/fixtures'
import type { SocialAccount } from './types'

/** Fictional social accounts for tests. */
export function makeSocialAccount(
  id: number,
  overrides: Partial<SocialAccount> = {},
): SocialAccount {
  return {
    id,
    platform: { value: 'instagram', label: 'Instagram' },
    username: `pixel_studio_${id}`,
    login_email: `social${id}@example.com`,
    created_on: '2026-08-15',
    is_in_use: false,
    has_password: true,
    platform_account_id: 10,
    platform_account: {
      id: 10,
      email: 'vale.accounts@example.com',
      discord_username: 'vale_accounts',
      standing: { value: 'active', label: 'Active' },
    },
    pending_change: null,
    created_at: '2026-08-15T10:00:00Z',
    updated_at: '2026-09-01T10:00:00Z',
    ...overrides,
  }
}

export { paginate }
