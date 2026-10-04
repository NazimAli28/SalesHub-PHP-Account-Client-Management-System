import { BellIcon } from 'lucide-react'
import { Link } from 'react-router'
import { Button } from '@/components/ui/button'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { useAuth } from '@/features/auth/AuthProvider'
import { useUnreadNotificationsCount } from '@/features/notifications/api'
import { paths } from '../paths'

export function NotificationsBell() {
  const { can } = useAuth()
  const allowed = can('notifications.view')
  const { data: unread = 0 } = useUnreadNotificationsCount({ enabled: allowed })
  if (!allowed) return null

  const label = unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button variant="ghost" size="icon" className="relative" asChild>
          <Link to={paths.notifications} aria-label={label}>
            <BellIcon aria-hidden="true" />
            {unread > 0 ? (
              <span
                aria-hidden="true"
                className="bg-primary text-primary-foreground tabular ring-background absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] leading-none font-semibold ring-2"
              >
                {unread > 99 ? '99+' : unread}
              </span>
            ) : null}
          </Link>
        </Button>
      </TooltipTrigger>
      <TooltipContent>{label}</TooltipContent>
    </Tooltip>
  )
}
