import type { AccountStanding, EnumValue, IsoDate, IsoDateTime, PendingChange } from '@/api/types'

export type SocialPlatform =
  | 'instagram'
  | 'x'
  | 'behance'
  | 'dribbble'
  | 'artstation'
  | 'tiktok'
  | 'youtube'
  | 'facebook'
  | 'pinterest'
  | 'other'

export interface PlatformAccountRef {
  id: number
  email: string
  discord_username: string | null
  standing: EnumValue<AccountStanding> | null
}

/** `SocialAccountResource`. The password is never returned: `has_password` says if one is stored. */
export interface SocialAccount {
  id: number
  platform: EnumValue<SocialPlatform>
  username: string
  login_email: string | null
  created_on: IsoDate | null
  is_in_use: boolean
  has_password: boolean
  platform_account_id: number
  platform_account?: PlatformAccountRef
  pending_change: PendingChange | null
  created_at: IsoDateTime
  updated_at: IsoDateTime
}
