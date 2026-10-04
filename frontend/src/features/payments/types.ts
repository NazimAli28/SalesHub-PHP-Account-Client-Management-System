/** Payment types, narrowed from the generated schema (enums and money are typed loosely there). */
import type {
  ClientSummary,
  EnumValue,
  IsoDate,
  IsoDateTime,
  Money,
  OrderStatus,
  PaymentMethod,
  PaymentStatus,
  PendingChange,
  UserSummary,
} from '@/api/types'
import type { EnumOption } from '@/lib/enums'

/** The order reference every payment row carries (`OrderSummaryResource`). */
export interface OrderSummary {
  id: number
  order_number: string
  type: EnumValue | null
  status: EnumValue<OrderStatus> | null
  client_id: number
  client?: ClientSummary
  ordered_on: IsoDate | null
  total: Money
  amount_paid: Money
  balance: Money
  overdue_payments_count: number
}

export interface Payment {
  id: number
  order_id: number
  sequence: number
  amount: Money
  currency: string
  due_date: IsoDate
  status: EnumValue<PaymentStatus>
  is_overdue: boolean
  paid_at: IsoDateTime | null
  method: EnumValue<PaymentMethod> | null
  reference: string | null
  notes: string | null
  recorded_by_id: number | null
  order?: OrderSummary
  recorded_by?: UserSummary | null
  pending_change: PendingChange | null
  created_at: IsoDateTime
  updated_at: IsoDateTime
}

/** Compact installment used by the Client 360 lists. */
export interface PaymentSummary {
  id: number
  order_id: number
  order_number?: string
  sequence: number
  amount: Money
  due_date: IsoDate
  status: EnumValue<PaymentStatus>
  is_overdue: boolean
}

export const PAYMENT_METHOD_OPTIONS: readonly EnumOption<PaymentMethod>[] = [
  { value: 'paypal', label: 'PayPal' },
  { value: 'stripe', label: 'Stripe' },
  { value: 'wise', label: 'Wise' },
  { value: 'bank_transfer', label: 'Bank Transfer' },
  { value: 'other', label: 'Other' },
]
