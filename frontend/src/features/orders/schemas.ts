import { z } from 'zod'
import type { OrderStatus } from '@/api/types'
import { toIsoDate } from '@/lib/format'
import type { Order, OrderItem } from './types'

const STATUSES = [
  'pending_payment',
  'in_progress',
  'delivered',
  'completed',
  'cancelled',
  'refunded',
] as const satisfies readonly OrderStatus[]

const MAX_CENTS = 100_000_000

// ---------------------------------------------------------------------------
// Line items (shared by the "New order" form and the item add / edit dialog)
// ---------------------------------------------------------------------------

const itemFields = {
  service_id: z
    .number({ error: 'Choose a service.' })
    .int()
    .positive('Choose a service.')
    .nullable(),
  quantity: z
    .number({ error: 'Enter a quantity.' })
    .int('Enter a whole number.')
    .min(1, 'At least 1.')
    .max(1000, 'At most 1,000.'),
  unit_price_cents: z
    .number({ error: 'Enter a price.' })
    .nullable()
    .refine(
      (value) => value === null || Number.isFinite(value),
      'Enter an amount like 25 or 24.99.',
    )
    .refine(
      (value) => value === null || (value >= 0 && value <= MAX_CENTS),
      'Enter an amount up to $1,000,000.',
    ),
}

function requireItemBasics(
  item: { service_id: number | null; unit_price_cents: number | null },
  context: z.RefinementCtx,
  path: (string | number)[],
) {
  if (item.service_id === null) {
    context.addIssue({
      code: 'custom',
      path: [...path, 'service_id'],
      message: 'Choose a service.',
    })
  }
  if (item.unit_price_cents === null) {
    context.addIssue({
      code: 'custom',
      path: [...path, 'unit_price_cents'],
      message: 'Enter a price.',
    })
  }
}

export interface ItemInput {
  quantity: number
  unit_price_cents: number | null
}

/** Cents of one line; incomplete or invalid input counts as zero so the live total never shows NaN. */
export function lineTotalCents(item: ItemInput): number {
  const quantity = Number.isFinite(item.quantity) ? item.quantity : 0
  const price =
    item.unit_price_cents !== null && Number.isFinite(item.unit_price_cents)
      ? item.unit_price_cents
      : 0
  return Math.round(quantity * price)
}

export function computeOrderTotals(
  items: readonly ItemInput[],
  discountCents: number | null,
): { subtotal: number; discount: number; total: number } {
  const subtotal = items.reduce((sum, item) => sum + lineTotalCents(item), 0)
  const discount =
    discountCents !== null && Number.isFinite(discountCents) ? Math.max(0, discountCents) : 0
  return { subtotal, discount, total: subtotal - discount }
}

// ---------------------------------------------------------------------------
// New order (POST /orders)
// ---------------------------------------------------------------------------

export const orderFormSchema = z
  .object({
    client_id: z
      .number({ error: 'Choose a client.' })
      .int()
      .positive('Choose a client.')
      .nullable(),
    items: z
      .array(z.object(itemFields))
      .min(1, 'Add at least one item.')
      .max(50, 'An order can have up to 50 items.'),
    discount_cents: z
      .number()
      .nullable()
      .refine(
        (value) => value === null || Number.isFinite(value),
        'Enter an amount like 25 or 24.99.',
      )
      .refine(
        (value) => value === null || (value >= 0 && value <= MAX_CENTS),
        'Enter an amount up to $1,000,000.',
      ),
    ordered_on: z
      .string()
      .nullable()
      .refine(
        (value) => !value || value <= toIsoDate(new Date()),
        'The order date cannot be in the future.',
      ),
    notes: z.string().max(5000, 'Keep it under 5,000 characters.'),
  })
  .superRefine((values, context) => {
    if (values.client_id === null) {
      context.addIssue({ code: 'custom', path: ['client_id'], message: 'Choose a client.' })
    }
    values.items.forEach((item, index) => requireItemBasics(item, context, ['items', index]))
    const { subtotal, discount } = computeOrderTotals(values.items, values.discount_cents)
    if (discount > subtotal) {
      context.addIssue({
        code: 'custom',
        path: ['discount_cents'],
        message: 'The discount cannot exceed the order subtotal.',
      })
    }
  })

export type OrderFormValues = z.infer<typeof orderFormSchema>

export interface OrderPayload {
  client_id: number
  items: { service_id: number; quantity: number; unit_price_cents: number }[]
  discount_cents: number
  ordered_on?: string
  notes: string | null
}

export function emptyItem(): OrderFormValues['items'][number] {
  return { service_id: null, quantity: 1, unit_price_cents: null }
}

export function orderFormDefaults(clientId: number | null = null): OrderFormValues {
  return {
    client_id: clientId,
    items: [emptyItem()],
    discount_cents: null,
    ordered_on: toIsoDate(new Date()),
    notes: '',
  }
}

export function toOrderPayload(values: OrderFormValues): OrderPayload {
  return {
    client_id: values.client_id!,
    items: values.items.map((item) => ({
      service_id: item.service_id!,
      quantity: item.quantity,
      unit_price_cents: item.unit_price_cents!,
    })),
    discount_cents: values.discount_cents ?? 0,
    ordered_on: values.ordered_on ?? undefined,
    notes: values.notes.trim() || null,
  }
}

// ---------------------------------------------------------------------------
// Single item (POST/PATCH /orders/{id}/items)
// ---------------------------------------------------------------------------

export const itemFormSchema = z
  .object({
    ...itemFields,
    description: z.string().max(255, 'Keep it under 255 characters.'),
  })
  .superRefine((item, context) => requireItemBasics(item, context, []))

export type ItemFormValues = z.infer<typeof itemFormSchema>

export interface ItemPayload {
  service_id: number
  quantity: number
  unit_price_cents: number
  description: string | null
}

export function itemFormDefaults(item?: OrderItem | null): ItemFormValues {
  return {
    service_id: item?.service_id ?? null,
    quantity: item?.quantity ?? 1,
    unit_price_cents: item?.unit_price.amount_cents ?? null,
    description: item?.description ?? '',
  }
}

export function toItemPayload(values: ItemFormValues): ItemPayload {
  return {
    service_id: values.service_id!,
    quantity: values.quantity,
    unit_price_cents: values.unit_price_cents!,
    description: values.description.trim() || null,
  }
}

// ---------------------------------------------------------------------------
// Edit order (PATCH /orders/{id}): status, discount, notes
// ---------------------------------------------------------------------------

export const orderEditSchema = z.object({
  status: z.enum(STATUSES),
  discount_cents: z
    .number()
    .nullable()
    .refine(
      (value) => value === null || Number.isFinite(value),
      'Enter an amount like 25 or 24.99.',
    )
    .refine(
      (value) => value === null || (value >= 0 && value <= MAX_CENTS),
      'Enter an amount up to $1,000,000.',
    ),
  notes: z.string().max(5000, 'Keep it under 5,000 characters.'),
  /** Only sent when the change goes to the approval queue. */
  reason: z.string().max(500, 'Keep it under 500 characters.'),
})

export type OrderEditValues = z.infer<typeof orderEditSchema>

export interface OrderEditPayload {
  status: OrderStatus
  discount_cents: number
  notes: string | null
  reason?: string
}

export function orderEditDefaults(order: Order): OrderEditValues {
  return {
    status: order.status.value,
    discount_cents: order.discount.amount_cents ?? 0,
    notes: order.notes ?? '',
    reason: '',
  }
}

export function toOrderEditPayload(values: OrderEditValues): OrderEditPayload {
  return {
    status: values.status,
    discount_cents: values.discount_cents ?? 0,
    notes: values.notes.trim() || null,
    reason: values.reason.trim() || undefined,
  }
}

/** Cents of the order total that no active (non-void) installment covers yet. */
export function unscheduledCents(order: Order): number {
  const scheduled = (order.payments ?? [])
    .filter((payment) => payment.status.value !== 'void')
    .reduce((sum, payment) => sum + (payment.amount.amount_cents ?? 0), 0)
  return Math.max(0, (order.total.amount_cents ?? 0) - scheduled)
}
