import { keepPreviousData, useQuery, type QueryClient } from '@tanstack/react-query'
import { api } from '@/api/client'
import { toApiQuery, type ListParams, type ListParamsConfig } from '@/api/list-params'
import { createQueryKeys } from '@/api/query-keys'
import type {
  ApprovalRequest,
  Envelope,
  IsoDateTime,
  Paginated,
  PendingApprovalsCount,
  User,
} from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'
import type { FilterOption } from '@/components/data-table'

export const approvalKeys = {
  ...createQueryKeys('approvals'),
  pendingCount: () => ['approvals', 'pending-count'] as const,
}

/** Pending approval counts for the sidebar badge. Polled every 60 s. */
export function usePendingApprovalsCount({ enabled = true }: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: approvalKeys.pendingCount(),
    queryFn: () => api.get<Envelope<PendingApprovalsCount>>('/approvals/pending-count'),
    select: (response) => response.data,
    refetchInterval: 60_000,
    enabled,
  })
}

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** One row of the before/after diff the API builds (values can be any JSON). */
export interface ApprovalDiffRow {
  field: string
  before: unknown
  after: unknown
}

/** The approval as returned by list and detail endpoints, with the loosely generated fields narrowed. */
export type ApprovalItem = Omit<
  ApprovalRequest,
  'diff' | 'can' | 'reviewed_at' | 'applied_at' | 'payload' | 'before' | 'after'
> & {
  diff: ApprovalDiffRow[]
  payload: Record<string, unknown> | null
  reviewed_at: IsoDateTime | null
  applied_at: IsoDateTime | null
  /** Only on the detail endpoint. */
  can?: { review: boolean; cancel: boolean }
}

export type ApprovalTab = 'review' | 'mine' | 'all'

// ---------------------------------------------------------------------------
// Queries
// ---------------------------------------------------------------------------

/** URL <-> API mapping for the queue. The tab is kept in the URL separately (`?tab=`). */
export const APPROVAL_LIST_CONFIG = {
  filterKeys: ['status', 'type', 'action', 'requester', 'created_from', 'created_to'],
  defaultSort: '-created_at',
} as const satisfies ListParamsConfig

/** Tab scoping on top of the URL filters. */
export interface ApprovalScope {
  /** Only requests the user may decide ("Needs my review"). */
  reviewableOnly?: boolean
  /** Only requests submitted by this user ("My requests"). */
  requesterId?: number
}

export function fetchApprovals(
  params: ListParams,
  scope: ApprovalScope,
  signal?: AbortSignal,
): Promise<Paginated<ApprovalItem>> {
  const query = toApiQuery(params)
  if (scope.reviewableOnly) query['filter[reviewable]'] = 'true'
  if (scope.requesterId) query['filter[requester]'] = scope.requesterId
  return api.get<Paginated<ApprovalItem>>('/approvals', { query, signal })
}

export function useApprovals(params: ListParams, scope: ApprovalScope = {}) {
  return useQuery({
    queryKey: approvalKeys.list({ ...params, ...scope }),
    queryFn: ({ signal }) => fetchApprovals(params, scope, signal),
    placeholderData: keepPreviousData,
  })
}

export function useApproval(id: number) {
  return useQuery({
    queryKey: approvalKeys.detail(id),
    queryFn: ({ signal }) => api.get<Envelope<ApprovalItem>>(`/approvals/${id}`, { signal }),
    select: (response) => response.data,
    enabled: Number.isInteger(id) && id > 0,
  })
}

/** Requester filter options (needs `users.view`). */
export function useRequesterOptions({ enabled }: { enabled: boolean }) {
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

// ---------------------------------------------------------------------------
// Decisions. A decision changes the queue, the sidebar badge and usually the target record,
// so these invalidate approvals plus the module caches the applier may have touched.
// ---------------------------------------------------------------------------

const DECISION_INVALIDATE = [
  approvalKeys.all,
  ['notifications'],
  ['leads'],
  ['clients'],
  ['orders'],
  ['payments'],
  ['platform-accounts'],
  ['social-accounts'],
]

export function decideApproval(
  id: number,
  decision: 'approve' | 'reject',
  comment?: string | null,
): Promise<ApprovalItem> {
  return api
    .post<Envelope<ApprovalItem>>(`/approvals/${id}/${decision}`, comment ? { comment } : {})
    .then((response) => response.data)
}

export function useApproveApproval() {
  return useApiMutation({
    mutationFn: ({ id, comment }: { id: number; comment?: string | null }) =>
      decideApproval(id, 'approve', comment),
    successMessage: 'Request approved',
    invalidate: DECISION_INVALIDATE,
  })
}

export function useRejectApproval() {
  return useApiMutation({
    mutationFn: ({ id, comment }: { id: number; comment: string }) =>
      decideApproval(id, 'reject', comment),
    successMessage: 'Request rejected',
    invalidate: DECISION_INVALIDATE,
  })
}

export function useCancelApproval() {
  return useApiMutation({
    mutationFn: (id: number) =>
      api.post<Envelope<ApprovalItem>>(`/approvals/${id}/cancel`).then((r) => r.data),
    successMessage: 'Request cancelled',
    invalidate: DECISION_INVALIDATE,
  })
}

/** For callers that decide without a mutation hook (bulk actions). */
export function invalidateAfterDecision(queryClient: QueryClient): Promise<unknown> {
  return Promise.all(
    DECISION_INVALIDATE.map((queryKey) => queryClient.invalidateQueries({ queryKey })),
  )
}
