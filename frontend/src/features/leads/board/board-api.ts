/**
 * Data layer for the leads Kanban board: one infinite query per stage column and an optimistic
 * stage-move mutation (rollback on error, and on 202 because the change only waits for approval).
 */
import {
  useInfiniteQuery,
  useQueryClient,
  type InfiniteData,
  type QueryClient,
} from '@tanstack/react-query'
import { api, isQueued } from '@/api/client'
import type { Lead, LeadLostReason, LeadStage, Paginated, PendingChange } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import { useAuth } from '@/features/auth/AuthProvider'
import { leadStages } from '@/lib/enums'
import { leadKeys } from '../api'

export const BOARD_PAGE_SIZE = 25
export const BOARD_SORT = '-stage_changed_at'

/** Pipeline order, same as the backend's `LeadStage::ordered()`. */
export const BOARD_STAGES: readonly LeadStage[] = leadStages.options.map((option) => option.value)

export interface BoardFilters {
  search: string
  owner: string[]
}

type BoardData = InfiniteData<Paginated<Lead>, number>

const boardKeys = {
  all: [...leadKeys.all, 'board'] as const,
  column: (stage: LeadStage, filters: BoardFilters) =>
    [...leadKeys.all, 'board', stage, filters] as const,
}

export function useBoardColumn(stage: LeadStage, filters: BoardFilters) {
  return useInfiniteQuery({
    queryKey: boardKeys.column(stage, filters),
    initialPageParam: 1,
    queryFn: ({ pageParam, signal }) =>
      api.get<Paginated<Lead>>('/leads', {
        signal,
        query: {
          'page[number]': pageParam,
          'page[size]': BOARD_PAGE_SIZE,
          sort: BOARD_SORT,
          'filter[stage]': stage,
          ...(filters.search.trim() ? { 'filter[search]': filters.search.trim() } : {}),
          ...(filters.owner.length > 0 ? { 'filter[owner]': filters.owner } : {}),
          include: 'client,owner',
        },
      }),
    getNextPageParam: (last) =>
      last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
  })
}

/** Looks a card up in whichever column cache holds it (used by drag events and announcements). */
export function findBoardLead(queryClient: QueryClient, id: number): Lead | undefined {
  for (const [, data] of queryClient.getQueriesData<BoardData>({ queryKey: boardKeys.all })) {
    for (const page of data?.pages ?? []) {
      const lead = page.data.find((candidate) => candidate.id === id)
      if (lead) return lead
    }
  }
  return undefined
}

type Snapshot = [readonly unknown[], BoardData | undefined][]

function stageOfKey(key: readonly unknown[]): LeadStage {
  return key[2] as LeadStage
}

/** Moves a lead between the cached columns and keeps the counts honest. */
function moveInCache(queryClient: QueryClient, lead: Lead, to: LeadStage): void {
  const moved: Lead = { ...lead, stage: { value: to, label: labelOf(to) } }
  for (const [key, data] of queryClient.getQueriesData<BoardData>({ queryKey: boardKeys.all })) {
    if (!data) continue
    const column = stageOfKey(key)
    if (column === lead.stage.value) {
      queryClient.setQueryData<BoardData>(key, {
        ...data,
        pages: data.pages.map((page) => ({
          ...page,
          data: page.data.filter((candidate) => candidate.id !== lead.id),
          meta: { ...page.meta, total: Math.max(0, page.meta.total - 1) },
        })),
      })
    } else if (column === to) {
      queryClient.setQueryData<BoardData>(key, {
        ...data,
        pages: data.pages.map((page, index) => ({
          ...page,
          data: index === 0 ? [moved, ...page.data] : page.data,
          meta: { ...page.meta, total: page.meta.total + 1 },
        })),
      })
    }
  }
}

function labelOf(stage: LeadStage): string {
  return leadStages.options.find((option) => option.value === stage)?.label ?? stage
}

function patchLead(queryClient: QueryClient, id: number, patch: Partial<Lead>): void {
  queryClient.setQueriesData<BoardData>({ queryKey: boardKeys.all }, (data) =>
    data
      ? {
          ...data,
          pages: data.pages.map((page) => ({
            ...page,
            data: page.data.map((lead) => (lead.id === id ? { ...lead, ...patch } : lead)),
          })),
        }
      : data,
  )
}

export interface MoveLeadVariables {
  lead: Lead
  stage: LeadStage
  lost_reason?: LeadLostReason
  lost_note?: string
  order_id?: number
}

/**
 * PATCH /leads/{id}/stage with an optimistic move. 200 keeps the move, 202 puts the card back
 * and marks it pending, and any error restores the snapshot (the base hook shows the toast).
 */
export function useMoveLeadStage() {
  const queryClient = useQueryClient()
  const { user } = useAuth()

  return useApiMutation({
    mutationFn: sendMove,
    successMessage: 'Stage updated',
    onMutate: async ({ lead, stage }): Promise<{ snapshot: Snapshot }> => {
      await queryClient.cancelQueries({ queryKey: boardKeys.all })
      const snapshot = queryClient.getQueriesData<BoardData>({ queryKey: boardKeys.all })
      moveInCache(queryClient, lead, stage)
      return { snapshot }
    },
    onError: (_error, _variables, context) => restore(queryClient, context),
    onSuccess: (result, { lead }, context) => {
      if (!isQueued(result)) return
      restore(queryClient, context)
      patchLead(queryClient, lead.id, {
        pending_change: {
          id: result.approval?.id ?? 0,
          action: { value: 'update', label: 'Update' },
          fields: ['stage'],
          requested_by: { id: user?.id ?? 0, name: user?.name ?? 'You', username: user?.username },
          requested_at: new Date().toISOString(),
        } as PendingChange,
      })
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: leadKeys.all }),
  })
}

function restore(queryClient: QueryClient, context: unknown): void {
  const snapshot = (context as { snapshot?: Snapshot } | undefined)?.snapshot
  for (const [key, data] of snapshot ?? []) queryClient.setQueryData(key, data)
}

function sendMove({ lead, stage, lost_reason, lost_note, order_id }: MoveLeadVariables) {
  return api.send<Lead>('PATCH', `/leads/${lead.id}/stage`, {
    stage,
    ...(stage === 'won' ? { order_id } : {}),
    ...(stage === 'lost' ? { lost_reason, lost_note: lost_note?.trim() || undefined } : {}),
  })
}

/** Display name for a lead card. */
export function leadName(lead: Lead): string {
  return lead.client?.name ?? lead.client?.discord_username ?? `Lead #${lead.id}`
}
