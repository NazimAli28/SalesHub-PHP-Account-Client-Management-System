import type { AccountStanding, EnumValue, IsoDate, IsoDateTime, PendingChange } from '@/api/types'
import type { SocialAccount } from '@/features/social-accounts/types'

export interface WorkstationRef {
  id: number
  code: string
  label: string | null
  team_id: number | null
  team?: { id: number; name: string } | null
}

/**
 * `PlatformAccountResource`. Credentials are never returned: the `has_*` flags say whether a
 * secret is stored, and the reveal endpoint returns the values on demand.
 */
export interface PlatformAccount {
  id: number
  email: string
  discord_email: string | null
  discord_username: string | null
  discord_created_on: IsoDate | null
  recovery_email: string | null
  batch_date: IsoDate | null
  standing: EnumValue<AccountStanding>
  standing_changed_at: IsoDateTime | null
  notes: string | null
  has_email_password: boolean
  has_discord_password: boolean
  has_recovery_phone: boolean
  has_phone_holder_name: boolean
  workstation_id: number | null
  assigned_at: IsoDateTime | null
  workstation?: WorkstationRef | null
  social_accounts_count?: number
  social_accounts?: SocialAccount[]
  pending_change: PendingChange | null
  created_at: IsoDateTime
  updated_at: IsoDateTime
}

/** The secret fields of a platform account, in display order. */
export const CREDENTIAL_FIELDS = [
  { key: 'email_password', label: 'Email password', has: 'has_email_password' },
  { key: 'discord_password', label: 'Discord password', has: 'has_discord_password' },
  { key: 'recovery_phone', label: 'Recovery phone', has: 'has_recovery_phone' },
  { key: 'phone_holder_name', label: 'Phone holder name', has: 'has_phone_holder_name' },
] as const

export type CredentialField = (typeof CREDENTIAL_FIELDS)[number]['key']
