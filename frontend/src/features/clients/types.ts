/** Client types, narrowed from the generated schema. */
import type {
  ClientStatus,
  EnumValue,
  IsoDate,
  IsoDateTime,
  Lead,
  Money,
  PendingChange,
  UserSummary,
} from '@/api/types'
import type { OrderSummary, PaymentSummary } from '@/features/payments/types'

export interface ClientRecord {
  id: number
  discord_username: string
  name: string | null
  email: string | null
  payment_name: string | null
  country: string | null
  status: EnumValue<ClientStatus> | null
  nurturing_rating: number | null
  next_upsell_plan: string | null
  expected_upsell_on: IsoDate | null
  lost_note: string | null
  notes: string | null
  owner_id: number | null
  /** Sum of paid payments (present on list and detail responses). */
  lifetime_value?: Money | null
  owner?: UserSummary | null
  pending_change: PendingChange | null
  created_at: IsoDateTime
  updated_at: IsoDateTime
}

/** `GET /clients/{id}`: the Client 360 payload. */
export interface ClientDetail extends ClientRecord {
  counts: {
    leads: number
    orders: number
    open_orders: number
    overdue_payments: number
  }
  leads?: Lead[]
  orders?: OrderSummary[]
  upcoming_payments?: PaymentSummary[]
  overdue_payments?: PaymentSummary[]
}

export function clientDisplayName(client: {
  name: string | null
  discord_username: string
}): string {
  return client.name ?? client.discord_username
}
