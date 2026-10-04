/** Payments data layer: the global due/overdue list and the installment mutations. */
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { UseFormReturn } from 'react-hook-form'
import { api } from '@/api/client'
import { toApiQuery, type ListParams, type ListParamsConfig } from '@/api/list-params'
import { createQueryKeys } from '@/api/query-keys'
import type { Paginated } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import type {
  MarkPaidFormValues,
  MarkPaidPayload,
  PaymentFormValues,
  PaymentPayload,
} from './schemas'
import type { Payment } from './types'

export const paymentKeys = createQueryKeys('payments')

/** A payment write changes order balances and the Client 360, so those caches go stale too. */
const AFFECTED_KEYS = [paymentKeys.all, ['orders'], ['clients']]

export const PAYMENT_LIST_CONFIG = {
  filterKeys: ['status', 'overdue', 'owner', 'team', 'due_from', 'due_to'],
  defaultSort: 'due_date',
} as const satisfies ListParamsConfig

const LIST_INCLUDES = ['order.client'] as const

export function fetchPayments(
  params: ListParams,
  signal?: AbortSignal,
): Promise<Paginated<Payment>> {
  return api.get<Paginated<Payment>>('/payments', {
    query: toApiQuery(params, { include: LIST_INCLUDES }),
    signal,
  })
}

export function usePayments(params: ListParams, { enabled = true }: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: paymentKeys.list(params),
    queryFn: ({ signal }) => fetchPayments(params, signal),
    placeholderData: keepPreviousData,
    enabled,
  })
}

interface FormMutationOptions<TValues extends object> {
  form?: UseFormReturn<TValues>
  onSuccess?: () => void
}

/** Schedules an installment on an order (always a direct write). */
export function useCreatePayment(
  orderId: number,
  { form, onSuccess }: FormMutationOptions<PaymentFormValues> = {},
) {
  return useApiMutation({
    mutationFn: (payload: PaymentPayload) =>
      api.send<Payment>('POST', `/orders/${orderId}/payments`, payload),
    successMessage: 'Installment scheduled',
    invalidate: AFFECTED_KEYS,
    form,
    onSuccess,
  })
}

/** May return 202 (queued for approval). */
export function useUpdatePayment({ form, onSuccess }: FormMutationOptions<PaymentFormValues> = {}) {
  return useApiMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<PaymentPayload> }) =>
      api.send<Payment>('PATCH', `/payments/${id}`, payload),
    successMessage: 'Installment updated',
    invalidate: AFFECTED_KEYS,
    form,
    onSuccess,
  })
}

/** 200 with the paid payment, or 202 when queued for approval. */
export function useMarkPaymentPaid({
  form,
  onSuccess,
}: FormMutationOptions<MarkPaidFormValues> = {}) {
  return useApiMutation({
    mutationFn: ({ id, payload }: { id: number; payload: MarkPaidPayload }) =>
      api.send<Payment>('POST', `/payments/${id}/mark-paid`, payload),
    successMessage: 'Payment recorded',
    invalidate: AFFECTED_KEYS,
    form,
    onSuccess,
  })
}

/** May return 202 (queued for approval). */
export function useDeletePayment() {
  return useApiMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
      api.send<void>('DELETE', `/payments/${id}`, reason ? { reason } : undefined),
    successMessage: 'Installment deleted',
    invalidate: AFFECTED_KEYS,
  })
}
