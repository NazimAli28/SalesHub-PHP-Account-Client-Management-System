/** Order types, narrowed from the generated schema (enums and money are typed loosely there). */
import type {
  ClientSummary,
  EnumValue,
  IsoDate,
  IsoDateTime,
  Money,
  OrderStatus,
  PendingChange,
  ServiceSummary,
  UserSummary,
} from '@/api/types'
import type { Payment } from '@/features/payments/types'

export interface OrderItem {
  id: number
  order_id: number
  service_id: number
  description: string | null
  quantity: number
  unit_price: Money
  line_total: Money
  service?: ServiceSummary
}

export interface Order {
  id: number
  order_number: string
  type: EnumValue | null
  status: EnumValue<OrderStatus>
  currency: string
  subtotal: Money
  discount: Money
  total: Money
  amount_paid: Money
  balance: Money
  overdue_payments_count: number
  ordered_on: IsoDate | null
  delivered_at: IsoDateTime | null
  notes: string | null
  client_id: number
  owner_id: number
  closer_id: number | null
  team_id: number | null
  platform_account_id: number | null
  parent_order_id: number | null
  client?: ClientSummary
  owner?: UserSummary
  closer?: UserSummary | null
  items?: OrderItem[]
  payments?: Payment[]
  pending_change: PendingChange | null
  created_at: IsoDateTime
  updated_at: IsoDateTime
}
