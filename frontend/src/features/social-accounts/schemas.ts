import { z } from 'zod'
import { toIsoDate } from '@/lib/format'
import { SOCIAL_PLATFORM_OPTIONS } from './platforms'
import type { SocialAccount, SocialPlatform } from './types'

const PLATFORMS = SOCIAL_PLATFORM_OPTIONS.map((option) => option.value) as [
  SocialPlatform,
  ...SocialPlatform[],
]

/** Client-side rules mirror backend/app/Http/Requests/SocialAccounts (the API stays the authority). */
export const socialAccountFormSchema = z.object({
  platform_account_id: z.number().int().positive('Choose a platform account.').nullable(),
  platform: z.enum(PLATFORMS),
  username: z
    .string()
    .trim()
    .min(1, 'Enter the username.')
    .max(100, 'Keep it under 100 characters.'),
  login_email: z
    .string()
    .trim()
    .max(255, 'Keep it under 255 characters.')
    .refine(
      (value) => value === '' || z.email().safeParse(value).success,
      'Enter a valid email address.',
    ),
  /** Write-only. Never prefilled; blank on edit means "keep the stored password". */
  password: z.string().max(255, 'Keep it under 255 characters.'),
  created_on: z
    .string()
    .nullable()
    .refine(
      (value) => !value || value <= toIsoDate(new Date()),
      'The creation date cannot be in the future.',
    ),
  is_in_use: z.boolean(),
  /** Only sent when the change goes to the approval queue. */
  reason: z.string().max(500, 'Keep it under 500 characters.'),
})

export type SocialAccountFormValues = z.infer<typeof socialAccountFormSchema>

/** Request body for POST /api/social-accounts and PATCH /api/social-accounts/{id}. */
export interface SocialAccountPayload {
  platform_account_id: number
  platform: SocialPlatform
  username: string
  login_email: string | null
  password?: string
  created_on: string | null
  is_in_use: boolean
  reason?: string
}

/** Never receives the stored password: it is not in the API response. */
export function socialAccountFormDefaults(account?: SocialAccount | null): SocialAccountFormValues {
  return {
    platform_account_id: account?.platform_account_id ?? null,
    platform: account?.platform.value ?? 'instagram',
    username: account?.username ?? '',
    login_email: account?.login_email ?? '',
    password: '',
    created_on: account?.created_on ?? null,
    is_in_use: account?.is_in_use ?? false,
    reason: '',
  }
}

export function toSocialAccountPayload(
  values: SocialAccountFormValues,
  { includePassword }: { includePassword: boolean },
): SocialAccountPayload {
  return {
    platform_account_id: values.platform_account_id!,
    platform: values.platform,
    username: values.username.trim(),
    login_email: values.login_email.trim() || null,
    ...(includePassword && values.password ? { password: values.password } : {}),
    created_on: values.created_on,
    is_in_use: values.is_in_use,
    reason: values.reason.trim() || undefined,
  }
}

/** For edits: only the fields the user changed (plus the approval reason). */
export function pickChanged<T extends object>(
  payload: T,
  dirty: Partial<Record<keyof T, unknown>>,
): Partial<T> {
  return Object.fromEntries(
    Object.entries(payload).filter(([key]) => key in dirty || key === 'reason'),
  ) as Partial<T>
}
