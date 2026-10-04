import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { UseFormReturn } from 'react-hook-form'
import { api } from '@/api/client'
import { toApiQuery, type ListParams, type ListParamsConfig } from '@/api/list-params'
import { createQueryKeys } from '@/api/query-keys'
import type { Paginated, Schemas } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import type { ComboboxOption } from '@/components/form'
import type { WorkstationFormValues, WorkstationPayload } from './schemas'

export type Workstation = Schemas['WorkstationResource']

export const workstationKeys = createQueryKeys('workstations')

export const WORKSTATION_LIST_CONFIG = {
  filterKeys: ['team', 'active'],
  defaultSort: 'code',
} as const satisfies ListParamsConfig

/** `users` is included so the table can show who sits at each workstation. */
const LIST_INCLUDES = ['users'] as const

export function useWorkstations(params: ListParams) {
  return useQuery({
    queryKey: workstationKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<Workstation>>('/workstations', {
        query: toApiQuery(params, { include: LIST_INCLUDES }),
        signal,
      }),
    placeholderData: keepPreviousData,
  })
}

interface WorkstationFormMutationOptions {
  form?: UseFormReturn<WorkstationFormValues>
  onSuccess?: () => void
}

// Users and teams show workstation info, so refresh them as well.
const INVALIDATE = [workstationKeys.all, ['users'], ['teams']]

export function useCreateWorkstation({ form, onSuccess }: WorkstationFormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: (payload: WorkstationPayload) =>
      api.send<Workstation>('POST', '/workstations', payload),
    successMessage: 'Workstation created',
    invalidate: INVALIDATE,
    form,
    onSuccess,
  })
}

export function useUpdateWorkstation({ form, onSuccess }: WorkstationFormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<WorkstationPayload> }) =>
      api.send<Workstation>('PATCH', `/workstations/${id}`, payload),
    successMessage: 'Workstation updated',
    invalidate: INVALIDATE,
    form,
    onSuccess,
  })
}

/** A 422 (users or platform accounts still attached) is toasted with the server's message. */
export function useDeleteWorkstation() {
  return useApiMutation({
    mutationFn: (id: number) => api.send<void>('DELETE', `/workstations/${id}`),
    successMessage: 'Workstation deleted',
    invalidate: INVALIDATE,
  })
}

/** Workstation picker source, limited to one team (a user's workstation must be on their team). */
export function makeWorkstationFetcher(teamId: number | null) {
  return async (search: string, signal: AbortSignal): Promise<ComboboxOption[]> => {
    if (teamId === null) return []
    const response = await api.get<Paginated<Workstation>>('/workstations', {
      query: {
        'filter[team]': teamId,
        'filter[active]': 'true',
        'filter[search]': search,
        'page[size]': 50,
        sort: 'code',
      },
      signal,
    })
    return response.data.map((workstation) => ({
      value: workstation.id,
      label: workstation.code,
      description: workstation.label ?? undefined,
    }))
  }
}
