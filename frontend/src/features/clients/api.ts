/**
 * Clients data layer: query keys, list/detail queries, mutations and the option loaders that
 * the Orders and Payments filters reuse (owner, team and client pickers).
 */
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { UseFormReturn } from 'react-hook-form'
import { api } from '@/api/client'
import { toApiQuery, type ListParams, type ListParamsConfig } from '@/api/list-params'
import { createQueryKeys } from '@/api/query-keys'
import type { Envelope, Paginated, Schemas, User } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import type { FilterOption } from '@/components/data-table'
import type { ComboboxOption } from '@/components/form'
import type { ClientFormValues, ClientPayload } from './schemas'
import { clientDisplayName, type ClientDetail, type ClientRecord } from './types'

export const clientKeys = createQueryKeys('clients')

export const CLIENT_LIST_CONFIG = {
  filterKeys: ['status', 'owner', 'has_open_orders', 'created_from', 'created_to'],
  defaultSort: '-created_at',
} as const satisfies ListParamsConfig

const LIST_INCLUDES = ['owner'] as const

export function fetchClients(
  params: ListParams,
  signal?: AbortSignal,
): Promise<Paginated<ClientRecord>> {
  return api.get<Paginated<ClientRecord>>('/clients', {
    query: toApiQuery(params, { include: LIST_INCLUDES }),
    signal,
  })
}

export function useClients(params: ListParams) {
  return useQuery({
    queryKey: clientKeys.list(params),
    queryFn: ({ signal }) => fetchClients(params, signal),
    placeholderData: keepPreviousData,
  })
}

/** Client 360 payload (leads, orders with balances, upcoming and overdue payments, counts). */
export function useClient(id: number, { enabled = true }: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: clientKeys.detail(id),
    queryFn: ({ signal }) => api.get<Envelope<ClientDetail>>(`/clients/${id}`, { signal }),
    select: (response) => response.data,
    enabled: enabled && Number.isFinite(id),
  })
}

// ---------------------------------------------------------------------------
// Mutations (update and delete may be queued for approval: 202)
// ---------------------------------------------------------------------------

interface ClientFormMutationOptions {
  form?: UseFormReturn<ClientFormValues>
  onSuccess?: () => void
}

export function useCreateClient({ form, onSuccess }: ClientFormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: (payload: ClientPayload) => api.send<ClientRecord>('POST', '/clients', payload),
    successMessage: 'Client created',
    invalidate: [clientKeys.all],
    form,
    onSuccess,
  })
}

export function useUpdateClient({ form, onSuccess }: ClientFormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<ClientPayload> }) =>
      api.send<ClientRecord>('PATCH', `/clients/${id}`, payload),
    successMessage: 'Client updated',
    // Orders and leads embed the client's name, so they go stale too.
    invalidate: [clientKeys.all, ['orders'], ['leads'], ['payments']],
    form,
    onSuccess,
  })
}

export function useDeleteClient({ onSuccess }: { onSuccess?: () => void } = {}) {
  return useApiMutation({
    mutationFn: (id: number) => api.send<void>('DELETE', `/clients/${id}`),
    successMessage: 'Client deleted',
    invalidate: [clientKeys.all, ['orders'], ['leads'], ['payments']],
    onSuccess,
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
  const response = await api.get<Paginated<ClientRecord>>('/clients', {
    query: { 'filter[search]': search, 'page[size]': 20, sort: 'name' },
    signal,
  })
  return response.data.map((client) => ({
    value: client.id,
    label: clientDisplayName(client),
    description: `@${client.discord_username}`,
  }))
}

/** User picker for the owner field (needs `users.view`). */
export async function fetchUserOptions(
  search: string,
  signal: AbortSignal,
): Promise<ComboboxOption[]> {
  const response = await api.get<Paginated<User>>('/users', {
    query: { 'filter[active]': 1, 'filter[search]': search, 'page[size]': 20, sort: 'name' },
    signal,
  })
  return response.data.map((user) => ({
    value: user.id,
    label: user.name,
    description: user.email,
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

/** Team filter options (every signed-in role may read teams). */
export function useTeamOptions({ enabled = true }: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: ['teams', 'options'],
    queryFn: ({ signal }) =>
      api.get<Paginated<Schemas['TeamResource']>>('/teams', {
        query: { 'page[size]': 100, sort: 'name' },
        signal,
      }),
    select: (response): FilterOption[] =>
      response.data.map((team) => ({ value: String(team.id), label: team.name })),
    enabled,
    staleTime: 5 * 60_000,
  })
}
