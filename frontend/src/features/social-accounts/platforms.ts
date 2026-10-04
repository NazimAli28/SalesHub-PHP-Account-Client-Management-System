import type { EnumOption } from '@/lib/enums'
import type { SocialPlatform } from './types'

/** Mirrors backend/app/Enums/SocialPlatform.php. */
export const SOCIAL_PLATFORM_OPTIONS: EnumOption<SocialPlatform>[] = [
  { value: 'instagram', label: 'Instagram' },
  { value: 'x', label: 'X' },
  { value: 'behance', label: 'Behance' },
  { value: 'dribbble', label: 'Dribbble' },
  { value: 'artstation', label: 'ArtStation' },
  { value: 'tiktok', label: 'TikTok' },
  { value: 'youtube', label: 'YouTube' },
  { value: 'facebook', label: 'Facebook' },
  { value: 'pinterest', label: 'Pinterest' },
  { value: 'other', label: 'Other' },
]
