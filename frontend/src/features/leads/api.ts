/**
 * Leads data layer: query keys, list/detail queries and mutations.
 * Pattern for every module: copy this file and change the resource name, path and types.
 */
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { toApiQuery, type ListParams, type ListParamsConfig } from '@/api/list-params'
import { createQueryKeys } from '@/api/query-keys'
import type { Client, Envelope, Lead, Paginated, User } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import type { ComboboxOption } from '@/components/form'
import type { FilterOption } from '@/components/data-table'
import type { UseFormReturn } from 'react-hook-form'
import type { LeadFormValues, LeadPayload } from './schemas'

interface LeadFormMutationOptions {
  /** 422 errors are shown on this form's fields instead of a toast. */
  form?: UseFormReturn<LeadFormValues>
  onSuccess?: () => void
}

export const leadKeys = createQueryKeys('leads')

/** URL <-> API mapping for the list (filter names are the API's `filter[...]` names). */
export const LEAD_LIST_CONFIG = {
  filterKeys: ['stage', 'owner'],
  defaultSort: '-contacted_on',
} as const satisfies ListParamsConfig

/** Relations the list needs for its columns. */
const LIST_INCLUDES = ['client', 'owner'] as const

export function fetchLeads(params: ListParams, signal?: AbortSignal): Promise<Paginated<Lead>> {
  return api.get<Paginated<Lead>>('/leads', {
    query: toApiQuery(params, { include: LIST_INCLUDES }),
    signal,
  })
}

export function useLeads(params: ListParams) {
  return useQuery({
    queryKey: leadKeys.list(params),
    queryFn: ({ signal }) => fetchLeads(params, signal),
    // Keep showing the current page while the next one loads (no flash of skeletons).
    placeholderData: keepPreviousData,
  })
}

export function useLead(id: number) {
  return useQuery({
    queryKey: leadKeys.detail(id),
    queryFn: ({ signal }) => api.get<Envelope<Lead>>(`/leads/${id}`, { signal }),
    select: (response) => response.data,
  })
}

// ---------------------------------------------------------------------------
// Mutations. Update and delete may be queued for approval (202): `api.send` reports that and
// useApiMutation shows "Sent for approval" instead of the success message.
// ---------------------------------------------------------------------------

export function useCreateLead({ form, onSuccess }: LeadFormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: (payload: LeadPayload) => api.send<Lead>('POST', '/leads', payload),
    successMessage: 'Lead created',
    invalidate: [leadKeys.all],
    form,
    onSuccess,
  })
}

export function useUpdateLead({ form, onSuccess }: LeadFormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<LeadPayload> }) =>
      api.send<Lead>('PATCH', `/leads/${id}`, payload),
    successMessage: 'Lead updated',
    invalidate: [leadKeys.all],
    form,
    onSuccess,
  })
}

export function useDeleteLead() {
  return useApiMutation({
    mutationFn: (id: number) => api.send<void>('DELETE', `/leads/${id}`),
    successMessage: 'Lead deleted',
    invalidate: [leadKeys.all],
  })
}

// ---------------------------------------------------------------------------
// Options for pickers and filters
// ---------------------------------------------------------------------------

/** Client picker: server-side search over name, email and Discord username. */
export async function fetchClientOptions(
  search: string,
  signal: AbortSignal,
): Promise<ComboboxOption[]> {
  const response = await api.get<Paginated<Client>>('/clients', {
    query: { 'filter[search]': search, 'page[size]': 20, sort: 'name' },
    signal,
  })
  return response.data.map((client) => ({
    value: client.id,
    label: client.name ?? client.discord_username,
    description: `@${client.discord_username}`,
  }))
}

/** Owner filter options (needs `users.view`; the API scopes the list to the user's team). */
export function useOwnerOptions({ enabled }: { enabled: boolean }) {
  return useQuery({
    queryKey: ['users', 'options', 'active'],
    queryFn: ({ signal }) =>
      api.get<Paginated<User>>('/users', {
        query: { 'filter[active]': 1, 'page[size]': 100, sort: 'name' },
        signal,
      }),
    select: (response): FilterOption[] =>
      response.data.map((user) => ({ value: String(user.id), label: user.name })),
    enabled,
    staleTime: 5 * 60_000,
  })
}
