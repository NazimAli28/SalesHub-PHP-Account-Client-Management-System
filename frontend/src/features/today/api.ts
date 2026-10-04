/** Data for the daily work list: payments due, lead follow-ups and the user's own requests. */
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type {
  EnumValue,
  IsoDate,
  Lead,
  Money,
  Paginated,
  PaymentStatus,
  PendingChange,
} from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import { approvalKeys } from '@/features/approvals/api'

/** The slice of a payment row the Today list needs (`GET /payments`, with order + client). */
export interface DuePayment {
  id: number
  order_id: number
  sequence: number
  amount: Money | null
  due_date: IsoDate | null
  status: EnumValue<PaymentStatus>
  is_overdue: boolean
  pending_change: PendingChange | null
  order?: {
    id: number
    order_number: string
    client?: { id: number; name: string | null; discord_username: string } | null
  }
}

const PAGE_SIZE = 100

/** Scheduled payments due on or before `today` (overdue ones included), oldest first. */
export function useDuePayments({ today, enabled }: { today: IsoDate; enabled: boolean }) {
  return useQuery({
    // Starts with 'payments' so any payment write refreshes it.
    queryKey: ['payments', 'due', today],
    queryFn: ({ signal }) =>
      api.get<Paginated<DuePayment>>('/payments', {
        query: {
          'filter[status]': 'scheduled',
          'filter[due_to]': today,
          sort: 'due_date',
          include: 'order,order.client',
          'page[size]': PAGE_SIZE,
        },
        signal,
      }),
    enabled,
  })
}

/**
 * Open leads whose follow-up is due today or overdue, oldest first. Filtering happens on the
 * server (`filter[open]`, `filter[follow_up_to]`), so nothing is missed past the first page.
 */
export function useFollowUpLeads({ today, enabled }: { today: IsoDate; enabled: boolean }) {
  return useQuery({
    queryKey: ['leads', 'follow-ups', today],
    queryFn: ({ signal }) =>
      api.get<Paginated<Lead>>('/leads', {
        query: {
          'filter[open]': 1,
          'filter[follow_up_to]': today,
          sort: 'next_follow_up_on',
          include: 'client,owner',
          'page[size]': PAGE_SIZE,
        },
        signal,
      }),
    select: (response) => response.data,
    enabled,
  })
}

/** One-click "mark as paid": today's date, no method. May be queued for approval (202). */
export function useMarkPaymentPaid() {
  return useApiMutation({
    mutationFn: (id: number) => api.send<DuePayment>('POST', `/payments/${id}/mark-paid`, {}),
    successMessage: 'Payment marked as paid',
    invalidate: [['payments'], ['orders'], approvalKeys.all],
  })
}
