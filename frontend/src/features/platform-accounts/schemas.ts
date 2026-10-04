import { z } from 'zod'
import type { AccountStanding } from '@/api/types'
import { toIsoDate } from '@/lib/format'
import type { PlatformAccount } from './types'

const STANDINGS = [
  'active',
  'limited',
  'spam',
  'violation',
  'disabled',
] as const satisfies readonly AccountStanding[]

const optionalEmail = z
  .string()
  .trim()
  .max(255, 'Keep it under 255 characters.')
  .refine(
    (value) => value === '' || z.email().safeParse(value).success,
    'Enter a valid email address.',
  )

/** Client-side rules mirror backend/app/Http/Requests/PlatformAccounts (the API stays the authority). */
export const platformAccountFormSchema = z.object({
  email: z
    .string()
    .trim()
    .min(1, 'Enter the account email.')
    .max(255, 'Keep it under 255 characters.')
    .refine(
      (value) => value === '' || z.email().safeParse(value).success,
      'Enter a valid email address.',
    ),
  discord_email: optionalEmail,
  discord_username: z.string().trim().max(64, 'Keep it under 64 characters.'),
  discord_created_on: z
    .string()
    .nullable()
    .refine(
      (value) => !value || value <= toIsoDate(new Date()),
      'The creation date cannot be in the future.',
    ),
  recovery_email: optionalEmail,
  batch_date: z.string().nullable(),
  notes: z.string().max(5000, 'Keep it under 5,000 characters.'),
  /** Create only. */
  workstation_id: z.number().int().positive().nullable(),
  /** Create only. */
  standing: z.enum(STANDINGS),
  /** Write-only credentials. Never prefilled; blank on edit means "keep the stored value". */
  email_password: z.string().max(255, 'Keep it under 255 characters.'),
  discord_password: z.string().max(255, 'Keep it under 255 characters.'),
  recovery_phone: z.string().max(64, 'Keep it under 64 characters.'),
  phone_holder_name: z.string().max(120, 'Keep it under 120 characters.'),
  /** Only sent when the change goes to the approval queue. */
  reason: z.string().max(500, 'Keep it under 500 characters.'),
})

export type PlatformAccountFormValues = z.infer<typeof platformAccountFormSchema>

/** Request body for POST /api/platform-accounts and PATCH /api/platform-accounts/{id}. */
export interface PlatformAccountPayload {
  email: string
  discord_email: string | null
  discord_username: string | null
  discord_created_on: string | null
  recovery_email: string | null
  batch_date: string | null
  notes: string | null
  workstation_id?: number | null
  standing?: AccountStanding
  email_password?: string
  discord_password?: string
  recovery_phone?: string
  phone_holder_name?: string
  reason?: string
}

/** Credentials start empty: the API never returns them, and the form never shows them. */
export function platformAccountFormDefaults(
  account?: PlatformAccount | null,
): PlatformAccountFormValues {
  return {
    email: account?.email ?? '',
    discord_email: account?.discord_email ?? '',
    discord_username: account?.discord_username ?? '',
    discord_created_on: account?.discord_created_on ?? null,
    recovery_email: account?.recovery_email ?? '',
    batch_date: account?.batch_date ?? toIsoDate(new Date()),
    notes: account?.notes ?? '',
    workstation_id: account?.workstation_id ?? null,
    standing: account?.standing.value ?? 'active',
    email_password: '',
    discord_password: '',
    recovery_phone: '',
    phone_holder_name: '',
    reason: '',
  }
}

export function toPlatformAccountPayload(
  values: PlatformAccountFormValues,
  { create, includeCredentials }: { create: boolean; includeCredentials: boolean },
): PlatformAccountPayload {
  const credentials: Partial<PlatformAccountPayload> = {}
  if (includeCredentials) {
    // Blank credentials are left out: on edit that keeps the stored value.
    if (values.email_password) credentials.email_password = values.email_password
    if (values.discord_password) credentials.discord_password = values.discord_password
    if (values.recovery_phone.trim()) credentials.recovery_phone = values.recovery_phone.trim()
    if (values.phone_holder_name.trim()) {
      credentials.phone_holder_name = values.phone_holder_name.trim()
    }
  }
  return {
    email: values.email.trim(),
    discord_email: values.discord_email.trim() || null,
    discord_username: values.discord_username.trim() || null,
    discord_created_on: values.discord_created_on,
    recovery_email: values.recovery_email.trim() || null,
    batch_date: values.batch_date,
    notes: values.notes.trim() || null,
    ...(create ? { workstation_id: values.workstation_id, standing: values.standing } : {}),
    ...credentials,
    reason: values.reason.trim() || undefined,
  }
}

export function pickChanged<T extends object>(
  payload: T,
  dirty: Partial<Record<keyof T, unknown>>,
): Partial<T> {
  return Object.fromEntries(
    Object.entries(payload).filter(([key]) => key in dirty || key === 'reason'),
  ) as Partial<T>
}

// ---------------------------------------------------------------------------
// Smaller forms: assign, change standing, request new accounts
// ---------------------------------------------------------------------------

export const assignFormSchema = z.object({
  workstation_id: z.number().int().positive().nullable(),
})
export type AssignFormValues = z.infer<typeof assignFormSchema>

export const standingFormSchema = z.object({
  standing: z.enum(STANDINGS),
  reason: z.string().max(500, 'Keep it under 500 characters.'),
})
export type StandingFormValues = z.infer<typeof standingFormSchema>

export const requestAccountsFormSchema = z.object({
  workstation_id: z.number().int().positive().nullable(),
  quantity: z
    .string()
    .trim()
    .refine(
      (value) => /^\d+$/.test(value) && Number(value) >= 1 && Number(value) <= 10,
      'Enter a whole number from 1 to 10.',
    ),
  note: z.string().max(500, 'Keep it under 500 characters.'),
})
export type RequestAccountsFormValues = z.infer<typeof requestAccountsFormSchema>
