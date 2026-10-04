/**
 * Enum options for selects and filters, mirrored from backend/app/Enums (labels match each
 * enum's `label()`), plus the colour tone each value gets in <StatusBadge>.
 *
 * Tones are semantic, so the same meaning looks the same everywhere:
 *   success = done / healthy, warning = needs attention, danger = failed / lost,
 *   info = in progress, brand = notable step forward, neutral = new / idle, muted = closed / void.
 */
import type {
  AccountStanding,
  ApprovalStatus,
  ClientStatus,
  LeadLostReason,
  LeadStage,
  OrderStatus,
  PaymentStatus,
} from '@/api/types'

export type Tone = 'neutral' | 'info' | 'brand' | 'success' | 'warning' | 'danger' | 'muted'

export interface EnumOption<TValue extends string = string> {
  value: TValue
  label: string
}

interface EnumDefinition<TValue extends string> {
  options: readonly EnumOption<TValue>[]
  tones: Record<TValue, Tone>
}

function defineEnum<TValue extends string>(
  entries: Record<TValue, [label: string, tone: Tone]>,
): EnumDefinition<TValue> {
  const pairs = Object.entries(entries) as [TValue, [string, Tone]][]
  return {
    options: pairs.map(([value, [label]]) => ({ value, label })),
    tones: Object.fromEntries(pairs.map(([value, [, tone]]) => [value, tone])) as Record<
      TValue,
      Tone
    >,
  }
}

export const leadStages = defineEnum<LeadStage>({
  new: ['New', 'neutral'],
  engaged: ['Engaged', 'info'],
  portfolio_shared: ['Portfolio Shared', 'info'],
  quoted: ['Quoted', 'brand'],
  payment_pending: ['Payment Pending', 'warning'],
  won: ['Won', 'success'],
  lost: ['Lost', 'danger'],
})

export const leadLostReasons = defineEnum<LeadLostReason>({
  no_response: ['No Response', 'muted'],
  price: ['Price', 'muted'],
  chose_competitor: ['Chose Competitor', 'muted'],
  not_ready: ['Not Ready', 'muted'],
  spam: ['Spam', 'muted'],
  other: ['Other', 'muted'],
})

export const accountStandings = defineEnum<AccountStanding>({
  active: ['Active', 'success'],
  limited: ['Limited', 'warning'],
  spam: ['Spam', 'danger'],
  violation: ['Violation', 'danger'],
  disabled: ['Disabled', 'muted'],
})

export const approvalStatuses = defineEnum<ApprovalStatus>({
  pending: ['Pending', 'warning'],
  approved: ['Approved', 'success'],
  rejected: ['Rejected', 'danger'],
  cancelled: ['Cancelled', 'muted'],
  failed: ['Failed', 'danger'],
})

export const clientStatuses = defineEnum<ClientStatus>({
  active: ['Active', 'success'],
  nurturing: ['Nurturing', 'info'],
  dormant: ['Dormant', 'muted'],
  lost: ['Lost', 'danger'],
})

export const orderStatuses = defineEnum<OrderStatus>({
  pending_payment: ['Pending Payment', 'warning'],
  in_progress: ['In Progress', 'info'],
  delivered: ['Delivered', 'brand'],
  completed: ['Completed', 'success'],
  cancelled: ['Cancelled', 'muted'],
  refunded: ['Refunded', 'danger'],
})

export const paymentStatuses = defineEnum<PaymentStatus>({
  scheduled: ['Scheduled', 'info'],
  paid: ['Paid', 'success'],
  void: ['Void', 'muted'],
})

/** Lookup used by <StatusBadge kind="..."> */
export const STATUS_ENUMS = {
  leadStage: leadStages,
  leadLostReason: leadLostReasons,
  accountStanding: accountStandings,
  approvalStatus: approvalStatuses,
  clientStatus: clientStatuses,
  orderStatus: orderStatuses,
  paymentStatus: paymentStatuses,
} as const

export type StatusKind = keyof typeof STATUS_ENUMS

export function toneFor(kind: StatusKind, value: string): Tone {
  const tones = STATUS_ENUMS[kind].tones as Record<string, Tone>
  return tones[value] ?? 'neutral'
}

export function labelFor(kind: StatusKind, value: string): string {
  const option = (STATUS_ENUMS[kind].options as readonly EnumOption[]).find(
    (o) => o.value === value,
  )
  return option?.label ?? value
}
