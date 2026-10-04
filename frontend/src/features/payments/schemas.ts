import { z } from 'zod'
import type { PaymentMethod } from '@/api/types'
import { toIsoDate } from '@/lib/format'
import type { Payment } from './types'

const METHODS = [
  'paypal',
  'stripe',
  'wise',
  'bank_transfer',
  'other',
] as const satisfies readonly PaymentMethod[]

const cents = (message: string) =>
  z
    .number({ error: message })
    .nullable()
    .refine((value) => value === null || Number.isFinite(value), message)

// ---------------------------------------------------------------------------
// Schedule / edit an installment (POST /orders/{id}/payments, PATCH /payments/{id})
// ---------------------------------------------------------------------------

export const paymentFormSchema = z
  .object({
    amount_cents: cents('Enter an amount like 250 or 249.99.'),
    due_date: z.string().nullable(),
    status: z.enum(['scheduled', 'void']),
    method: z.enum(METHODS).nullable(),
    reference: z.string().max(100, 'Keep it under 100 characters.'),
    notes: z.string().max(2000, 'Keep it under 2,000 characters.'),
    /** Only sent when the change goes to the approval queue. */
    reason: z.string().max(500, 'Keep it under 500 characters.'),
  })
  .superRefine((values, context) => {
    if (values.amount_cents === null || values.amount_cents < 1) {
      context.addIssue({
        code: 'custom',
        path: ['amount_cents'],
        message: 'Enter an amount greater than zero.',
      })
    } else if (values.amount_cents > 100_000_000) {
      context.addIssue({
        code: 'custom',
        path: ['amount_cents'],
        message: 'Enter an amount up to $1,000,000.',
      })
    }
    if (!values.due_date) {
      context.addIssue({ code: 'custom', path: ['due_date'], message: 'Choose a due date.' })
    }
  })

export type PaymentFormValues = z.infer<typeof paymentFormSchema>

export interface PaymentPayload {
  amount_cents: number
  due_date: string
  status?: 'scheduled' | 'void'
  method: PaymentMethod | null
  reference: string | null
  notes: string | null
  reason?: string
}

export function paymentFormDefaults(
  payment?: Payment | null,
  suggestedCents: number | null = null,
): PaymentFormValues {
  return {
    amount_cents: payment?.amount.amount_cents ?? suggestedCents,
    due_date: payment?.due_date ?? null,
    status: payment?.status.value === 'void' ? 'void' : 'scheduled',
    method: payment?.method?.value ?? null,
    reference: payment?.reference ?? '',
    notes: payment?.notes ?? '',
    reason: '',
  }
}

export function toPaymentPayload(values: PaymentFormValues, withStatus: boolean): PaymentPayload {
  return {
    amount_cents: values.amount_cents!,
    due_date: values.due_date!,
    ...(withStatus ? { status: values.status } : {}),
    method: values.method,
    reference: values.reference.trim() || null,
    notes: values.notes.trim() || null,
    reason: values.reason.trim() || undefined,
  }
}

// ---------------------------------------------------------------------------
// Mark paid (POST /payments/{id}/mark-paid)
// ---------------------------------------------------------------------------

export const markPaidSchema = z.object({
  paid_at: z
    .string()
    .nullable()
    .refine(
      (value) => !value || value <= toIsoDate(new Date()),
      'The payment date cannot be in the future.',
    ),
  method: z.enum(METHODS).nullable(),
  reference: z.string().max(100, 'Keep it under 100 characters.'),
  notes: z.string().max(2000, 'Keep it under 2,000 characters.'),
  reason: z.string().max(500, 'Keep it under 500 characters.'),
})

export type MarkPaidFormValues = z.infer<typeof markPaidSchema>

export interface MarkPaidPayload {
  paid_at?: string
  method: PaymentMethod | null
  reference: string | null
  notes: string | null
  reason?: string
}

export function markPaidDefaults(payment?: Payment | null): MarkPaidFormValues {
  return {
    paid_at: toIsoDate(new Date()),
    method: payment?.method?.value ?? null,
    reference: payment?.reference ?? '',
    notes: '',
    reason: '',
  }
}

export function toMarkPaidPayload(values: MarkPaidFormValues): MarkPaidPayload {
  return {
    paid_at: values.paid_at ?? undefined,
    method: values.method,
    reference: values.reference.trim() || null,
    notes: values.notes.trim() || null,
    reason: values.reason.trim() || undefined,
  }
}
