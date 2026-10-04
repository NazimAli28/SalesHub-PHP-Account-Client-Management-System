import { useMemo } from 'react'
import { isToday, isYesterday } from 'date-fns'
import {
  BellIcon,
  CheckCheckIcon,
  CheckIcon,
  CircleAlertIcon,
  ClipboardListIcon,
  XIcon,
  type LucideIcon,
} from 'lucide-react'
import { Link, useSearchParams } from 'react-router'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { EmptyState } from '@/components/layout/EmptyState'
import { ErrorState } from '@/components/layout/ErrorState'
import { TableSkeleton } from '@/components/layout/LoadingSkeleton'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import { detailPath } from '@/app/paths'
import { parseDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotifications,
  useUnreadNotificationsCount,
  type AppNotification,
} from '../api'

type Group = 'Today' | 'Yesterday' | 'Earlier'
const GROUP_ORDER: Group[] = ['Today', 'Yesterday', 'Earlier']

function groupOf(notification: AppNotification): Group {
  const date = parseDate(notification.created_at)
  if (date && isToday(date)) return 'Today'
  if (date && isYesterday(date)) return 'Yesterday'
  return 'Earlier'
}

function iconFor(notification: AppNotification): { icon: LucideIcon; className: string } {
  switch (notification.data.status) {
    case 'approved':
      return {
        icon: CheckIcon,
        className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
      }
    case 'rejected':
      return { icon: XIcon, className: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' }
    case 'failed':
      return {
        icon: CircleAlertIcon,
        className: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
      }
    default:
      return { icon: ClipboardListIcon, className: 'bg-muted text-muted-foreground' }
  }
}

function NotificationItem({
  notification,
  onRead,
  pending,
}: {
  notification: AppNotification
  onRead: (id: string) => void
  pending: boolean
}) {
  const { icon: Icon, className } = iconFor(notification)
  const approvalId = notification.data.approval_request_id
  const message = notification.data.message ?? 'You have a new notification.'
  const comment = notification.data.review_comment

  const body = (
    <>
      <span
        className={cn('flex size-8 shrink-0 items-center justify-center rounded-full', className)}
      >
        <Icon className="size-4" aria-hidden="true" />
      </span>
      <span className="min-w-0 flex-1 space-y-0.5">
        <span className={cn('block text-sm', !notification.is_read && 'font-medium')}>
          {message}
          {!notification.is_read ? <span className="sr-only"> (unread)</span> : null}
        </span>
        {comment ? (
          <span className="text-muted-foreground block text-sm break-words">
            &ldquo;{comment}&rdquo;
          </span>
        ) : null}
        <RelativeTime value={notification.created_at} className="text-muted-foreground text-xs" />
      </span>
    </>
  )

  return (
    <li className="flex items-start gap-2 p-3">
      {approvalId ? (
        <Link
          to={detailPath.approval(approvalId)}
          className="hover:bg-muted/50 -m-1 flex min-w-0 flex-1 items-start gap-3 rounded-md p-1"
          onClick={() => {
            if (!notification.is_read) onRead(notification.id)
          }}
        >
          {body}
        </Link>
      ) : (
        <div className="flex min-w-0 flex-1 items-start gap-3">{body}</div>
      )}
      {!notification.is_read ? (
        <Button
          variant="ghost"
          size="sm"
          disabled={pending}
          onClick={() => onRead(notification.id)}
          aria-label={`Mark as read: ${message}`}
        >
          Mark read
        </Button>
      ) : null}
    </li>
  )
}

export default function NotificationsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const unreadOnly = searchParams.get('unread') === '1'
  const notifications = useNotifications({ unreadOnly })
  const unreadCount = useUnreadNotificationsCount()
  const markRead = useMarkNotificationRead()
  const markAll = useMarkAllNotificationsRead()

  const items = useMemo(
    () => notifications.data?.pages.flatMap((page) => page.data) ?? [],
    [notifications.data],
  )
  const grouped = useMemo(() => {
    const groups = new Map<Group, AppNotification[]>()
    for (const item of items) {
      const group = groupOf(item)
      groups.set(group, [...(groups.get(group) ?? []), item])
    }
    return GROUP_ORDER.filter((group) => groups.has(group)).map((group) => ({
      group,
      items: groups.get(group)!,
    }))
  }, [items])

  const setUnreadOnly = (value: boolean) =>
    setSearchParams(value ? { unread: '1' } : {}, { replace: true, preventScrollReset: true })

  const hasUnread = (unreadCount.data ?? 0) > 0

  return (
    <div className="space-y-6">
      <PageHeader
        title="Notifications"
        description="Decisions on your requests and new requests waiting for your review."
        actions={
          <Button
            variant="outline"
            disabled={!hasUnread || markAll.isPending}
            onClick={() => markAll.mutate()}
          >
            {markAll.isPending ? <Spinner /> : <CheckCheckIcon aria-hidden="true" />}
            Mark all as read
          </Button>
        }
      />

      <div className="flex gap-2" role="group" aria-label="Filter notifications">
        <Button
          variant={unreadOnly ? 'outline' : 'secondary'}
          size="sm"
          aria-pressed={!unreadOnly}
          onClick={() => setUnreadOnly(false)}
        >
          All
        </Button>
        <Button
          variant={unreadOnly ? 'secondary' : 'outline'}
          size="sm"
          aria-pressed={unreadOnly}
          onClick={() => setUnreadOnly(true)}
        >
          Unread
        </Button>
      </div>

      {notifications.isPending ? (
        <TableSkeleton rows={4} columns={2} />
      ) : notifications.isError ? (
        <ErrorState error={notifications.error} onRetry={() => notifications.refetch()} />
      ) : items.length === 0 ? (
        <EmptyState
          icon={BellIcon}
          title={unreadOnly ? "You're all caught up" : 'No notifications yet'}
          description={
            unreadOnly
              ? 'No unread notifications.'
              : 'Updates on your approval requests will appear here.'
          }
        />
      ) : (
        <div className="space-y-6">
          {grouped.map(({ group, items: groupItems }) => (
            <section key={group} aria-labelledby={`notifications-${group}`}>
              <h2
                id={`notifications-${group}`}
                className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase"
              >
                {group}
              </h2>
              <ul className="divide-y rounded-xl border">
                {groupItems.map((notification) => (
                  <NotificationItem
                    key={notification.id}
                    notification={notification}
                    pending={markRead.isPending}
                    onRead={(id) => markRead.mutate(id)}
                  />
                ))}
              </ul>
            </section>
          ))}
          {notifications.hasNextPage ? (
            <div className="flex justify-center">
              <Button
                variant="outline"
                disabled={notifications.isFetchingNextPage}
                onClick={() => notifications.fetchNextPage()}
              >
                {notifications.isFetchingNextPage ? <Spinner /> : null}
                Load more
              </Button>
            </div>
          ) : null}
        </div>
      )}
    </div>
  )
}
