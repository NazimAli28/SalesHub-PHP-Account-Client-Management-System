import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { createQueryKeys } from '@/api/query-keys'
import type { Envelope, IsoDateTime, Paginated, UnreadNotificationsCount } from '@/api/types'
import { useApiMutation } from '@/api/use-api-mutation'

export const notificationKeys = {
  ...createQueryKeys('notifications'),
  unreadCount: () => ['notifications', 'unread-count'] as const,
}

/** Polled every 60 s while the tab is visible (TanStack Query pauses intervals in background tabs). */
export function useUnreadNotificationsCount({ enabled = true }: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: notificationKeys.unreadCount(),
    queryFn: () => api.get<Envelope<UnreadNotificationsCount>>('/notifications/unread-count'),
    select: (response) => response.data.unread,
    refetchInterval: 60_000,
    enabled,
  })
}

// ---------------------------------------------------------------------------
// List and mutations
// ---------------------------------------------------------------------------

/** Payload built by the backend notification classes (approval requested / decided). */
export interface NotificationData {
  approval_request_id?: number
  action?: string
  status?: string
  approvable_type?: string | null
  approvable_id?: number | null
  message?: string
  reviewed_by?: string | null
  review_comment?: string | null
  [key: string]: unknown
}

export interface AppNotification {
  id: string
  /** Short class name, e.g. `ApprovalDecided`, `ApprovalRequested`. */
  type: string
  data: NotificationData
  is_read: boolean
  read_at: IsoDateTime | null
  created_at: IsoDateTime
}

const PAGE_SIZE = 25

export function useNotifications({ unreadOnly }: { unreadOnly: boolean }) {
  return useInfiniteQuery({
    queryKey: notificationKeys.list({ unreadOnly }),
    queryFn: ({ pageParam, signal }) =>
      api.get<Paginated<AppNotification>>('/notifications', {
        query: {
          'page[number]': pageParam,
          'page[size]': PAGE_SIZE,
          ...(unreadOnly ? { 'filter[unread]': 'true' } : {}),
        },
        signal,
      }),
    initialPageParam: 1,
    getNextPageParam: (last) =>
      last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
  })
}

export function useMarkNotificationRead() {
  return useApiMutation({
    mutationFn: (id: string) =>
      api.patch<Envelope<AppNotification>>(`/notifications/${id}/read`).then((r) => r.data),
    invalidate: [notificationKeys.all],
  })
}

export function useMarkAllNotificationsRead() {
  return useApiMutation({
    mutationFn: () =>
      api.post<Envelope<{ updated: number }>>('/notifications/read-all').then((r) => r.data),
    successMessage: 'All notifications marked as read',
    invalidate: [notificationKeys.all],
  })
}
