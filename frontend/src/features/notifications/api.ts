import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { createQueryKeys } from '@/api/query-keys'
import type { Envelope, UnreadNotificationsCount } from '@/api/types'

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
