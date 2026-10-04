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
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { formatRelative } from '@/lib/format'
import type { PlatformAccount } from '../types'
import { PlatformAccountRowActions } from './PlatformAccountRowActions'

const column = createColumnHelper<PlatformAccount>()

interface PlatformAccountColumnOptions {
  onEdit: (account: PlatformAccount) => void
}

/** Sortable ids are API sort fields: batch_date, standing, standing_changed_at, assigned_at, email. */
export function getPlatformAccountColumns({
  onEdit,
}: PlatformAccountColumnOptions): DataTableColumn<PlatformAccount>[] {
  return column.columns([
    column.accessor('email', {
      id: 'email',
      enableSorting: true,
      enableHiding: false,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Account" />,
      meta: { label: 'Account', className: 'min-w-52' },
      cell: ({ row }) => (
        <div className="flex min-w-0 flex-col">
          <Link
            to={detailPath.platformAccount(row.original.id)}
            className="truncate font-medium underline-offset-4 hover:underline"
          >
            {row.original.email}
          </Link>
          {row.original.discord_username ? (
            <span className="text-muted-foreground truncate text-xs">
              @{row.original.discord_username}
            </span>
          ) : null}
        </div>
      ),
    }),
    column.accessor('standing', {
      id: 'standing',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Standing" />,
      meta: { label: 'Standing' },
      cell: ({ row }) => {
        const pending = row.original.pending_change
        return (
          <div className="flex items-center gap-1.5">
            <StatusBadge kind="accountStanding" value={row.original.standing} />
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
    column.accessor((account) => account.workstation?.code ?? '', {
      id: 'workstation',
      header: 'Workstation',
      meta: { label: 'Workstation' },
      cell: ({ row }) => {
        const workstation = row.original.workstation
        if (!workstation) return <span className="text-muted-foreground">Unassigned</span>
        return (
          <div className="flex flex-col">
            <span className="font-medium whitespace-nowrap">{workstation.code}</span>
            {workstation.team ? (
              <span className="text-muted-foreground text-xs">{workstation.team.name}</span>
            ) : null}
          </div>
        )
      },
    }),
    column.accessor('batch_date', {
      id: 'batch_date',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Batch" />,
      meta: { label: 'Batch date' },
      cell: ({ getValue }) => <RelativeTime value={getValue()} display="date" />,
    }),
    column.accessor('social_accounts_count', {
      id: 'social_accounts_count',
      header: () => <span className="block text-right">Socials</span>,
      meta: { label: 'Social accounts', className: 'text-right' },
      cell: ({ getValue }) => <span className="tabular-nums">{getValue() ?? 0}</span>,
    }),
    column.accessor('assigned_at', {
      id: 'assigned_at',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Assigned" />,
      meta: { label: 'Assigned' },
      cell: ({ getValue }) => <RelativeTime value={getValue()} className="text-muted-foreground" />,
    }),
    column.accessor('standing_changed_at', {
      id: 'standing_changed_at',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Standing changed" />,
      meta: { label: 'Standing changed' },
      cell: ({ getValue }) => <RelativeTime value={getValue()} className="text-muted-foreground" />,
    }),
    column.display({
      id: 'actions',
      enableHiding: false,
      header: () => <span className="sr-only">Actions</span>,
      meta: { className: 'w-12 text-right' },
      cell: ({ row }) => <PlatformAccountRowActions account={row.original} onEdit={onEdit} />,
    }),
  ])
}
