import { z } from 'zod'
import type { Lead, LeadLostReason, LeadStage } from '@/api/types'
import { toIsoDate } from '@/lib/format'

const STAGES = [
  'new',
  'engaged',
  'portfolio_shared',
  'quoted',
  'payment_pending',
  'won',
  'lost',
] as const satisfies readonly LeadStage[]
const LOST_REASONS = [
  'no_response',
  'price',
  'chose_competitor',
  'not_ready',
  'spam',
  'other',
] as const satisfies readonly LeadLostReason[]

/**
 * Client-side rules mirror backend/app/Http/Requests/Leads (the API stays the authority: its
 * 422 messages are mapped onto these same fields).
 */
export const leadFormSchema = z
  .object({
    client_id: z
      .number({ error: 'Choose a client.' })
      .int()
      .positive('Choose a client.')
      .nullable(),
    stage: z.enum(STAGES),
    contacted_on: z
      .string()
      .nullable()
      .refine(
        (value) => !value || value <= toIsoDate(new Date()),
        'The contact date cannot be in the future.',
      ),
    estimated_value_cents: z
      .number()
      .nullable()
      .refine(
        (value) => value === null || Number.isFinite(value),
        'Enter an amount like 250 or 249.99.',
      )
      .refine(
        (value) => value === null || (value >= 0 && value <= 100_000_000),
        'Enter an amount up to $1,000,000.',
      ),
    next_follow_up_on: z.string().nullable(),
    last_message: z.string().max(5000, 'Keep it under 5,000 characters.'),
    lost_reason: z.enum(LOST_REASONS).nullable(),
    /** Only sent when the change goes to the approval queue. */
    reason: z.string().max(500, 'Keep it under 500 characters.'),
  })
  .superRefine((values, context) => {
    if (values.client_id === null) {
      context.addIssue({ code: 'custom', path: ['client_id'], message: 'Choose a client.' })
    }
    if (values.stage === 'lost' && !values.lost_reason) {
      context.addIssue({
        code: 'custom',
        path: ['lost_reason'],
        message: 'A lost reason is required when the lead is lost.',
      })
    }
  })

export type LeadFormValues = z.infer<typeof leadFormSchema>

/** Request body for POST /api/leads and PATCH /api/leads/{id}. */
export interface LeadPayload {
  client_id: number
  stage: LeadStage
  contacted_on?: string
  estimated_value_cents: number | null
  currency?: string
  next_follow_up_on: string | null
  last_message: string | null
  lost_reason: LeadLostReason | null
  reason?: string
}

export function leadFormDefaults(lead?: Lead | null): LeadFormValues {
  return {
    client_id: lead?.client_id ?? null,
    stage: lead?.stage.value ?? 'new',
    contacted_on: lead?.contacted_on ?? toIsoDate(new Date()),
    estimated_value_cents: lead?.estimated_value?.amount_cents ?? null,
    next_follow_up_on: lead?.next_follow_up_on ?? null,
    last_message: lead?.last_message ?? '',
    lost_reason: lead?.lost_reason?.value ?? null,
    reason: '',
  }
}

export function toLeadPayload(values: LeadFormValues): LeadPayload {
  return {
    client_id: values.client_id!,
    stage: values.stage,
    contacted_on: values.contacted_on ?? undefined,
    estimated_value_cents: values.estimated_value_cents,
    next_follow_up_on: values.next_follow_up_on,
    last_message: values.last_message.trim() || null,
    lost_reason: values.stage === 'lost' ? values.lost_reason : null,
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
