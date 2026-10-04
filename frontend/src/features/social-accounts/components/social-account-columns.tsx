import { ClockIcon } from 'lucide-react'
import { Link } from 'react-router'
import { detailPath } from '@/app/paths'
import {
  createColumnHelper,
  DataTableColumnHeader,
  type DataTableColumn,
} from '@/components/data-table'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { StatusBadge } from '@/components/data-display/StatusBadge'
import { Badge } from '@/components/ui/badge'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { formatRelative } from '@/lib/format'
import type { SocialAccount } from '../types'
import { SocialAccountRowActions } from './SocialAccountRowActions'

const column = createColumnHelper<SocialAccount>()

interface SocialAccountColumnOptions {
  onEdit: (account: SocialAccount) => void
}

/** Sortable ids are API sort fields: platform, username, is_in_use, created_on, created_at. */
export function getSocialAccountColumns({
  onEdit,
}: SocialAccountColumnOptions): DataTableColumn<SocialAccount>[] {
  return column.columns([
    column.accessor('username', {
      id: 'username',
      enableSorting: true,
      enableHiding: false,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Username" />,
      meta: { label: 'Username', className: 'min-w-40' },
      cell: ({ row }) => {
        const pending = row.original.pending_change
        return (
          <div className="flex items-center gap-1.5">
            <div className="flex min-w-0 flex-col">
              <span className="truncate font-medium">@{row.original.username}</span>
              {row.original.login_email ? (
                <span className="text-muted-foreground truncate text-xs">
                  {row.original.login_email}
                </span>
              ) : null}
            </div>
            {pending ? (
              <Tooltip>
                <TooltipTrigger asChild>
                  <span tabIndex={0} className="text-amber-600 dark:text-amber-400">
                    <ClockIcon className="size-3.5" aria-hidden="true" />
                    <span className="sr-only">Change pending approval</span>
                  </span>
                </TooltipTrigger>
                <TooltipContent>
                  {pending.action.label} pending approval · requested by {pending.requested_by.name}{' '}
                  {formatRelative(pending.requested_at)}
                </TooltipContent>
              </Tooltip>
            ) : null}
          </div>
        )
      },
    }),
    column.accessor((account) => account.platform.label, {
      id: 'platform',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Platform" />,
      meta: { label: 'Platform' },
      cell: ({ getValue }) => <Badge variant="secondary">{getValue()}</Badge>,
    }),
    column.accessor((account) => account.platform_account?.email ?? '', {
      id: 'platform_account',
      header: 'Platform account',
      meta: { label: 'Platform account', className: 'min-w-44' },
      cell: ({ row }) => {
        const parent = row.original.platform_account
        if (!parent) return <span className="text-muted-foreground">—</span>
        return (
          <div className="flex min-w-0 flex-col gap-0.5">
            <Link
              to={detailPath.platformAccount(parent.id)}
              className="truncate font-medium underline-offset-4 hover:underline"
            >
              {parent.email}
            </Link>
            <span>
              <StatusBadge kind="accountStanding" value={parent.standing} />
            </span>
          </div>
        )
      },
    }),
    column.accessor('is_in_use', {
      id: 'is_in_use',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="In use" />,
      meta: { label: 'In use' },
      cell: ({ getValue }) =>
        getValue() ? (
          <Badge>In use</Badge>
        ) : (
          <span className="text-muted-foreground text-sm">Available</span>
        ),
    }),
    column.accessor('has_password', {
      id: 'has_password',
      header: 'Password',
      meta: { label: 'Password' },
      cell: ({ getValue }) => (
        <span className={getValue() ? undefined : 'text-muted-foreground'}>
          {getValue() ? 'Set' : 'Not set'}
        </span>
      ),
    }),
    column.accessor('created_on', {
      id: 'created_on',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Created" />,
      meta: { label: 'Created' },
      cell: ({ getValue }) => <RelativeTime value={getValue()} display="date" />,
    }),
    column.display({
      id: 'actions',
      enableHiding: false,
      header: () => <span className="sr-only">Actions</span>,
      meta: { className: 'w-12 text-right' },
      cell: ({ row }) => <SocialAccountRowActions account={row.original} onEdit={onEdit} />,
    }),
  ])
}
