import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { UseFormReturn } from 'react-hook-form'
import { api } from '@/api/client'
import { toApiQuery, type ListParams, type ListParamsConfig } from '@/api/list-params'
import { createQueryKeys } from '@/api/query-keys'
import type { Paginated, Schemas, ServiceCategory } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import type { EnumOption } from '@/lib/enums'
import type { ServiceFormValues, ServicePayload } from './schemas'

export type Service = Schemas['ServiceResource']

/** Labels match App\Enums\ServiceCategory::label(). */
export const SERVICE_CATEGORY_OPTIONS: readonly EnumOption<ServiceCategory>[] = [
  { value: 'branding', label: 'Branding' },
  { value: 'emotes', label: 'Emotes' },
  { value: 'overlays', label: 'Overlays' },
  { value: 'packages', label: 'Packages' },
  { value: 'animation', label: 'Animation' },
  { value: 'other', label: 'Other' },
]

export const serviceKeys = createQueryKeys('services')

export const SERVICE_LIST_CONFIG = {
  filterKeys: ['category', 'active'],
  defaultSort: 'name',
} as const satisfies ListParamsConfig

export function useServices(params: ListParams) {
  return useQuery({
    queryKey: serviceKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<Service>>('/services', { query: toApiQuery(params), signal }),
    placeholderData: keepPreviousData,
  })
}

interface ServiceFormMutationOptions {
  form?: UseFormReturn<ServiceFormValues>
  onSuccess?: () => void
}

export function useCreateService({ form, onSuccess }: ServiceFormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: (payload: ServicePayload) => api.send<Service>('POST', '/services', payload),
    successMessage: 'Service created',
    invalidate: [serviceKeys.all],
    form,
    onSuccess,
  })
}

export function useUpdateService({ form, onSuccess }: ServiceFormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<ServicePayload> }) =>
      api.send<Service>('PATCH', `/services/${id}`, payload),
    successMessage: 'Service updated',
    invalidate: [serviceKeys.all],
    form,
    onSuccess,
  })
}

export function useSetServiceActive() {
  return useApiMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) =>
      api.send<Service>('PATCH', `/services/${id}`, { is_active: active }),
    successMessage: (_data, { active }) => (active ? 'Service activated' : 'Service deactivated'),
    invalidate: [serviceKeys.all],
  })
}

export function useDeleteService() {
  return useApiMutation({
    mutationFn: (id: number) => api.send<void>('DELETE', `/services/${id}`),
    successMessage: 'Service deleted',
    invalidate: [serviceKeys.all],
  })
}
