import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { createQueryKeys } from '@/api/query-keys'
import type { Envelope, PendingApprovalsCount } from '@/api/types'

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
