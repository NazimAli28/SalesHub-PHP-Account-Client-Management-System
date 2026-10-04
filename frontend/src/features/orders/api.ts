/** Orders data layer: list/detail queries, order and item mutations, service picker. */
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import type { UseFormReturn } from 'react-hook-form'
import { api, isQueued } from '@/api/client'
import { toApiQuery, type ListParams, type ListParamsConfig } from '@/api/list-params'
import { createQueryKeys } from '@/api/query-keys'
import type { Envelope, Paginated, Schemas } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import type { ComboboxOption } from '@/components/form'
import type {
  ItemFormValues,
  ItemPayload,
  OrderEditPayload,
  OrderEditValues,
  OrderFormValues,
  OrderPayload,
} from './schemas'
import type { Order } from './types'

export const orderKeys = createQueryKeys('orders')

export const ORDER_LIST_CONFIG = {
  filterKeys: ['status', 'owner', 'team', 'client', 'has_overdue', 'ordered_from', 'ordered_to'],
  defaultSort: '-ordered_on',
} as const satisfies ListParamsConfig

const LIST_INCLUDES = ['client', 'owner'] as const

export function fetchOrders(params: ListParams, signal?: AbortSignal): Promise<Paginated<Order>> {
  return api.get<Paginated<Order>>('/orders', {
    query: toApiQuery(params, { include: LIST_INCLUDES }),
    signal,
  })
}

export function useOrders(params: ListParams) {
  return useQuery({
    queryKey: orderKeys.list(params),
    queryFn: ({ signal }) => fetchOrders(params, signal),
    placeholderData: keepPreviousData,
  })
}

/** `GET /orders/{id}` always embeds client, owner, items (with service) and payments. */
export function useOrder(id: number) {
  return useQuery({
    queryKey: orderKeys.detail(id),
    queryFn: ({ signal }) => api.get<Envelope<Order>>(`/orders/${id}`, { signal }),
    select: (response) => response.data,
    enabled: Number.isFinite(id),
  })
}

/** Other caches that show order money: the Client 360 and the global payments list. */
const RELATED_KEYS = [['clients'], ['payments']]

// ---------------------------------------------------------------------------
// Order mutations
// ---------------------------------------------------------------------------

export function useCreateOrder({
  form,
  onSuccess,
}: {
  form?: UseFormReturn<OrderFormValues>
  onSuccess?: (order: Order) => void
} = {}) {
  return useApiMutation({
    mutationFn: (payload: OrderPayload) => api.send<Order>('POST', '/orders', payload),
    successMessage: 'Order created',
    invalidate: [orderKeys.all, ['leads'], ...RELATED_KEYS],
    form,
    onSuccess: (result) => {
      if (!isQueued(result)) onSuccess?.(result.data)
    },
  })
}

/** May return 202 (queued for approval). */
export function useUpdateOrder({
  form,
  onSuccess,
}: {
  form?: UseFormReturn<OrderEditValues>
  onSuccess?: () => void
} = {}) {
  return useApiMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<OrderEditPayload> }) =>
      api.send<Order>('PATCH', `/orders/${id}`, payload),
    successMessage: 'Order updated',
    invalidate: [orderKeys.all, ...RELATED_KEYS],
    form,
    onSuccess,
  })
}

/** May return 202 (queued for approval). */
export function useDeleteOrder({ onSuccess }: { onSuccess?: () => void } = {}) {
  return useApiMutation({
    mutationFn: (id: number) => api.send<void>('DELETE', `/orders/${id}`),
    successMessage: 'Order deleted',
    invalidate: [orderKeys.all, ...RELATED_KEYS],
    onSuccess,
  })
}

// ---------------------------------------------------------------------------
// Item mutations. The API answers with the parent order and its recalculated totals,
// so the cached order is replaced with the response (no extra round trip).
// ---------------------------------------------------------------------------

interface ItemMutationOptions {
  form?: UseFormReturn<ItemFormValues>
  onSuccess?: () => void
}

function useStoreOrderFromResponse(orderId: number) {
  const queryClient = useQueryClient()
  return (result: { kind: string; data?: Order }) => {
    if (result.kind === 'applied' && result.data) {
      queryClient.setQueryData<Envelope<Order>>(orderKeys.detail(orderId), { data: result.data })
    }
  }
}

export function useAddOrderItem(orderId: number, { form, onSuccess }: ItemMutationOptions = {}) {
  const store = useStoreOrderFromResponse(orderId)
  return useApiMutation({
    mutationFn: (payload: ItemPayload) =>
      api.send<Order>('POST', `/orders/${orderId}/items`, payload),
    successMessage: 'Item added',
    // Detail is replaced from the response; lists and other money views refetch.
    invalidate: [orderKeys.lists(), ...RELATED_KEYS],
    form,
    onSuccess: (result) => {
      store(result)
      onSuccess?.()
    },
  })
}

export function useUpdateOrderItem(orderId: number, { form, onSuccess }: ItemMutationOptions = {}) {
  const store = useStoreOrderFromResponse(orderId)
  return useApiMutation({
    mutationFn: ({ itemId, payload }: { itemId: number; payload: Partial<ItemPayload> }) =>
      api.send<Order>('PATCH', `/orders/${orderId}/items/${itemId}`, payload),
    successMessage: 'Item updated',
    invalidate: [orderKeys.lists(), ...RELATED_KEYS],
    form,
    onSuccess: (result) => {
      store(result)
      onSuccess?.()
    },
  })
}

export function useRemoveOrderItem(orderId: number) {
  const store = useStoreOrderFromResponse(orderId)
  return useApiMutation({
    mutationFn: (itemId: number) => api.send<Order>('DELETE', `/orders/${orderId}/items/${itemId}`),
    successMessage: 'Item removed',
    invalidate: [orderKeys.lists(), ...RELATED_KEYS],
    onSuccess: store,
  })
}

// ---------------------------------------------------------------------------
// Service picker (items editor)
// ---------------------------------------------------------------------------

/** Base prices of services the user has seen in the picker, so choosing one can pre-fill the price. */
const servicePrices = new Map<number, number>()

export function baseCentsFor(serviceId: number | null): number | undefined {
  return serviceId === null ? undefined : servicePrices.get(serviceId)
}

export async function fetchServiceOptions(
  search: string,
  signal: AbortSignal,
): Promise<ComboboxOption[]> {
  const response = await api.get<Paginated<Schemas['ServiceResource']>>('/services', {
    query: { 'filter[search]': search, 'filter[active]': 1, 'page[size]': 20, sort: 'name' },
    signal,
  })
  return response.data.map((service) => {
    if (service.base_price) servicePrices.set(service.id, service.base_price.amount_cents)
    return {
      value: service.id,
      label: service.name,
      description: [service.category?.label, service.base_price?.formatted]
        .filter(Boolean)
        .join(' · '),
    }
  })
}
