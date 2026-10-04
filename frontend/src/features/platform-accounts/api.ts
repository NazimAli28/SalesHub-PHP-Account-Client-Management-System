import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { FieldValues, UseFormReturn } from 'react-hook-form'
import { api } from '@/api/client'
import { toApiQuery, type ListParams, type ListParamsConfig } from '@/api/list-params'
import { createQueryKeys } from '@/api/query-keys'
import type { AccountStanding, Envelope, Paginated } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import type { FilterOption } from '@/components/data-table'
import type { ComboboxOption } from '@/components/form'
import type { PlatformAccountFormValues, PlatformAccountPayload } from './schemas'
import type { PlatformAccount, WorkstationRef } from './types'

interface FormMutationOptions<TValues extends FieldValues = PlatformAccountFormValues> {
  form?: UseFormReturn<TValues>
  onSuccess?: () => void
}

export const platformAccountKeys = createQueryKeys('platform-accounts')

export const PLATFORM_ACCOUNT_LIST_CONFIG = {
  filterKeys: ['standing', 'workstation', 'team', 'assigned', 'batch_from', 'batch_to'],
  defaultSort: '-batch_date',
} as const satisfies ListParamsConfig

export function usePlatformAccounts(params: ListParams) {
  return useQuery({
    queryKey: platformAccountKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<PlatformAccount>>('/platform-accounts', {
        query: toApiQuery(params),
        signal,
      }),
    placeholderData: keepPreviousData,
  })
}

export function usePlatformAccount(id: number) {
  return useQuery({
    queryKey: platformAccountKeys.detail(id),
    queryFn: ({ signal }) =>
      api.get<Envelope<PlatformAccount>>(`/platform-accounts/${id}`, {
        query: { include: 'socialAccounts' },
        signal,
      }),
    select: (response) => response.data,
  })
}

// ---------------------------------------------------------------------------
// Mutations. Update, delete and standing may be queued for approval (202).
// ---------------------------------------------------------------------------

export function useCreatePlatformAccount({ form, onSuccess }: FormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: (payload: PlatformAccountPayload) =>
      api.send<PlatformAccount>('POST', '/platform-accounts', payload),
    successMessage: 'Platform account created',
    invalidate: [platformAccountKeys.all],
    form,
    onSuccess,
  })
}

export function useUpdatePlatformAccount({ form, onSuccess }: FormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<PlatformAccountPayload> }) =>
      api.send<PlatformAccount>('PATCH', `/platform-accounts/${id}`, payload),
    successMessage: 'Platform account updated',
    invalidate: [platformAccountKeys.all],
    form,
    onSuccess,
  })
}

export function useDeletePlatformAccount() {
  return useApiMutation({
    mutationFn: (id: number) => api.send<void>('DELETE', `/platform-accounts/${id}`),
    successMessage: 'Platform account deleted',
    invalidate: [platformAccountKeys.all, ['social-accounts']],
  })
}

/** `platform-accounts.assign`: a direct write, `null` unassigns. */
export function useAssignWorkstation<TValues extends FieldValues>({
  form,
  onSuccess,
}: FormMutationOptions<TValues> = {}) {
  return useApiMutation({
    mutationFn: ({ id, workstation_id }: { id: number; workstation_id: number | null }) =>
      api.send<PlatformAccount>('PATCH', `/platform-accounts/${id}/assign`, { workstation_id }),
    successMessage: 'Workstation updated',
    invalidate: [platformAccountKeys.all],
    form,
    onSuccess,
  })
}

/** Direct for `change-standing`, queued (202) for `request-change`. */
export function useChangeStanding<TValues extends FieldValues>({
  form,
  onSuccess,
}: FormMutationOptions<TValues> = {}) {
  return useApiMutation({
    mutationFn: ({
      id,
      standing,
      reason,
    }: {
      id: number
      standing: AccountStanding
      reason?: string
    }) =>
      api.send<PlatformAccount>('PATCH', `/platform-accounts/${id}/standing`, { standing, reason }),
    successMessage: 'Standing updated',
    invalidate: [platformAccountKeys.all],
    form,
    onSuccess,
  })
}

export interface RequestAccountsPayload {
  workstation_id?: number
  quantity: number
  note?: string
}

/** Always answers 202: a request for new accounts is reviewed before support provisions them. */
export function useRequestNewAccounts<TValues extends FieldValues>({
  form,
  onSuccess,
}: FormMutationOptions<TValues> = {}) {
  return useApiMutation({
    mutationFn: (payload: RequestAccountsPayload) =>
      api.send<unknown>('POST', '/platform-accounts/request-new', payload),
    queuedMessage: 'Sent for approval',
    invalidate: [['approvals']],
    form,
    onSuccess,
  })
}

// ---------------------------------------------------------------------------
// Options for pickers and filters
// ---------------------------------------------------------------------------

function workstationLabel(workstation: WorkstationRef): string {
  return workstation.label ? `${workstation.code} · ${workstation.label}` : workstation.code
}

/** Workstation picker (active workstations; the API validates the user's scope on writes). */
export async function fetchWorkstationOptions(
  search: string,
  signal: AbortSignal,
): Promise<ComboboxOption[]> {
  const response = await api.get<Paginated<WorkstationRef>>('/workstations', {
    query: { 'filter[search]': search, 'filter[active]': 1, 'page[size]': 25, sort: 'code' },
    signal,
  })
  return response.data.map((workstation) => ({
    value: workstation.id,
    label: workstationLabel(workstation),
    description: workstation.team?.name,
  }))
}

/** Workstation filter facet (needs `workstations.view`). */
export function useWorkstationFilterOptions({ enabled }: { enabled: boolean }) {
  return useQuery({
    queryKey: ['workstations', 'options', 'filter'],
    queryFn: ({ signal }) =>
      api.get<Paginated<WorkstationRef>>('/workstations', {
        query: { 'filter[active]': 1, 'page[size]': 100, sort: 'code' },
        signal,
      }),
    select: (response): FilterOption[] =>
      response.data.map((workstation) => ({
        value: String(workstation.id),
        label: workstationLabel(workstation),
      })),
    enabled,
    staleTime: 5 * 60_000,
  })
}

/** Team filter facet (needs `teams.view`). */
export function useTeamFilterOptions({ enabled }: { enabled: boolean }) {
  return useQuery({
    queryKey: ['teams', 'options', 'filter'],
    queryFn: ({ signal }) =>
      api.get<Paginated<{ id: number; name: string }>>('/teams', {
        query: { 'page[size]': 100, sort: 'name' },
        signal,
      }),
    select: (response): FilterOption[] =>
      response.data.map((team) => ({ value: String(team.id), label: team.name })),
    enabled,
    staleTime: 5 * 60_000,
  })
}
