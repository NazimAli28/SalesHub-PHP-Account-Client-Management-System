import { z } from 'zod'
import type { ClientStatus } from '@/api/types'
import type { ClientRecord } from './types'

const STATUSES = [
  'active',
  'nurturing',
  'dormant',
  'lost',
] as const satisfies readonly ClientStatus[]

/**
 * Client-side rules mirror backend/app/Http/Requests/Clients (the API stays the authority: its
 * 422 messages are mapped onto these same fields).
 */
export const clientFormSchema = z.object({
  discord_username: z
    .string()
    .trim()
    .min(1, 'Enter the Discord username.')
    .max(64, 'Keep it under 64 characters.'),
  name: z.string().max(120, 'Keep it under 120 characters.'),
  email: z
    .string()
    .trim()
    .max(255, 'Keep it under 255 characters.')
    .refine(
      (value) => value === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value),
      'Enter a valid email.',
    ),
  payment_name: z.string().max(120, 'Keep it under 120 characters.'),
  country: z
    .string()
    .trim()
    .refine((value) => value === '' || /^[A-Za-z]{2}$/.test(value), 'Use a 2-letter country code.'),
  status: z.enum(STATUSES),
  nurturing_rating: z
    .string()
    .trim()
    .refine(
      (value) => value === '' || (/^\d{1,3}$/.test(value) && Number(value) <= 100),
      'Enter a whole number from 0 to 100.',
    ),
  next_upsell_plan: z.string().max(5000, 'Keep it under 5,000 characters.'),
  expected_upsell_on: z.string().nullable(),
  lost_note: z.string().max(2000, 'Keep it under 2,000 characters.'),
  notes: z.string().max(5000, 'Keep it under 5,000 characters.'),
  owner_id: z.number().int().positive().nullable(),
  /** Only sent when the change goes to the approval queue. */
  reason: z.string().max(500, 'Keep it under 500 characters.'),
})

export type ClientFormValues = z.infer<typeof clientFormSchema>

/** Request body for POST /api/clients and PATCH /api/clients/{id}. */
export interface ClientPayload {
  discord_username: string
  name: string | null
  email: string | null
  payment_name: string | null
  country: string | null
  status: ClientStatus
  nurturing_rating: number | null
  next_upsell_plan: string | null
  expected_upsell_on: string | null
  lost_note: string | null
  notes: string | null
  owner_id: number | null
  reason?: string
}

export function clientFormDefaults(client?: ClientRecord | null): ClientFormValues {
  return {
    discord_username: client?.discord_username ?? '',
    name: client?.name ?? '',
    email: client?.email ?? '',
    payment_name: client?.payment_name ?? '',
    country: client?.country ?? '',
    status: client?.status?.value ?? 'active',
    nurturing_rating: client?.nurturing_rating?.toString() ?? '',
    next_upsell_plan: client?.next_upsell_plan ?? '',
    expected_upsell_on: client?.expected_upsell_on ?? null,
    lost_note: client?.lost_note ?? '',
    notes: client?.notes ?? '',
    owner_id: client?.owner_id ?? null,
    reason: '',
  }
}

const orNull = (value: string) => value.trim() || null

export function toClientPayload(values: ClientFormValues): ClientPayload {
  return {
    discord_username: values.discord_username.trim(),
    name: orNull(values.name),
    email: orNull(values.email),
    payment_name: orNull(values.payment_name),
    country: values.country.trim() ? values.country.trim().toUpperCase() : null,
    status: values.status,
    nurturing_rating: values.nurturing_rating.trim() ? Number(values.nurturing_rating) : null,
    next_upsell_plan: orNull(values.next_upsell_plan),
    expected_upsell_on: values.expected_upsell_on,
    lost_note: values.status === 'lost' ? orNull(values.lost_note) : null,
    notes: orNull(values.notes),
    owner_id: values.owner_id,
    reason: values.reason.trim() || undefined,
  }
}

/** For edits: only the fields the user changed (PATCH is partial; approvals show a clean diff). */
export function pickChanged<T extends object>(
  payload: T,
  dirty: Partial<Record<keyof T, unknown>>,
): Partial<T> {
  return Object.fromEntries(
    Object.entries(payload).filter(([key]) => key in dirty || key === 'reason'),
  ) as Partial<T>
}
