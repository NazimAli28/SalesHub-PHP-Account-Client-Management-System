import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { UseFormReturn } from 'react-hook-form'
import { api } from '@/api/client'
import { toApiQuery, type ListParams, type ListParamsConfig } from '@/api/list-params'
import { createQueryKeys } from '@/api/query-keys'
import type { Envelope, Paginated, Schemas, User } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import type { FilterOption } from '@/components/data-table'
import type { ComboboxOption } from '@/components/form'
import type { EnumOption } from '@/lib/enums'
import type { TeamFormValues, TeamPayload } from './schemas'

export type Team = Schemas['TeamResource']

export const SHIFT_OPTIONS: readonly EnumOption[] = [
  { value: 'morning', label: 'Morning' },
  { value: 'evening', label: 'Evening' },
  { value: 'night', label: 'Night' },
]

export const teamKeys = createQueryKeys('teams')

export const TEAM_LIST_CONFIG = {
  filterKeys: ['shift'],
  defaultSort: 'name',
} as const satisfies ListParamsConfig

export function useTeams(params: ListParams) {
  return useQuery({
    queryKey: teamKeys.list(params),
    queryFn: ({ signal }) =>
      api.get<Paginated<Team>>('/teams', { query: toApiQuery(params), signal }),
    placeholderData: keepPreviousData,
  })
}

/** One team with its members (the detail response always includes them). */
export function useTeam(id: number | null) {
  return useQuery({
    queryKey: teamKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<Envelope<Team>>(`/teams/${id}`, { signal }),
    select: (response) => response.data,
    enabled: id !== null,
  })
}

interface TeamFormMutationOptions {
  form?: UseFormReturn<TeamFormValues>
  onSuccess?: () => void
}

// Users carry their team, so team writes refresh the user list too.
const INVALIDATE = [teamKeys.all, ['users']]

export function useCreateTeam({ form, onSuccess }: TeamFormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: (payload: TeamPayload) => api.send<Team>('POST', '/teams', payload),
    successMessage: 'Team created',
    invalidate: INVALIDATE,
    form,
    onSuccess,
  })
}

export function useUpdateTeam({ form, onSuccess }: TeamFormMutationOptions = {}) {
  return useApiMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<TeamPayload> }) =>
      api.send<Team>('PATCH', `/teams/${id}`, payload),
    successMessage: 'Team updated',
    invalidate: INVALIDATE,
    form,
    onSuccess,
  })
}

/** A 422 (team still has members or workstations) is toasted with the server's message. */
export function useDeleteTeam() {
  return useApiMutation({
    mutationFn: (id: number) => api.send<void>('DELETE', `/teams/${id}`),
    successMessage: 'Team deleted',
    invalidate: INVALIDATE,
  })
}

// ---------------------------------------------------------------------------
// Options for pickers and filters
// ---------------------------------------------------------------------------

/** Team picker source (server-side search by name). */
export async function fetchTeamOptions(
  search: string,
  signal: AbortSignal,
): Promise<ComboboxOption[]> {
  const response = await api.get<Paginated<Team>>('/teams', {
    query: { 'filter[search]': search, 'page[size]': 20, sort: 'name' },
    signal,
  })
  return response.data.map((team) => ({
    value: team.id,
    label: team.name,
    description: `Floor ${team.floor} · ${team.shift?.label ?? 'No shift'}`,
  }))
}

/** Team filter facets. */
export function useTeamFilterOptions({ enabled = true }: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: ['teams', 'options', 'filter'],
    queryFn: ({ signal }) =>
      api.get<Paginated<Team>>('/teams', {
        query: { 'page[size]': 100, sort: 'name' },
        signal,
      }),
    select: (response): FilterOption[] =>
      response.data.map((team) => ({ value: String(team.id), label: team.name })),
    enabled,
    staleTime: 5 * 60_000,
  })
}

/**
 * Team lead picker. Mirrors TeamRules::teamLeadRule: an active team lead who already belongs to
 * this team, or, for a new team, one who is not on a team yet. (The API stays the authority.)
 */
export function makeTeamLeadFetcher(teamId: number | null) {
  return async (search: string, signal: AbortSignal): Promise<ComboboxOption[]> => {
    const query: Record<string, string | number> = {
      'filter[role]': 'team_lead',
      'filter[active]': 'true',
      'filter[search]': search,
      'page[size]': 50,
      sort: 'name',
    }
    if (teamId !== null) query['filter[team]'] = teamId
    const response = await api.get<Paginated<User>>('/users', { query, signal })
    return response.data
      .filter((user) => (teamId === null ? user.team_id === null : user.team_id === teamId))
      .map((user) => ({
        value: user.id,
        label: user.name,
        description: `@${user.username ?? user.email}`,
      }))
  }
}
