import { ClockIcon } from 'lucide-react'
import { Link } from 'react-router'
import { detailPath } from '@/app/paths'
import {
  createColumnHelper,
  DataTableColumnHeader,
  type DataTableColumn,
} from '@/components/data-table'
import { MoneyText } from '@/components/data-display/MoneyText'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { StatusBadge } from '@/components/data-display/StatusBadge'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { formatRelative } from '@/lib/format'
import { clientDisplayName, type ClientRecord } from '../types'
import { ClientRowActions } from './ClientRowActions'

const column = createColumnHelper<ClientRecord>()

interface ClientColumnOptions {
  onEdit: (client: ClientRecord) => void
}

/** Columns for the Clients table. Sortable ones use the API sort field as their `id`. */
export function getClientColumns({ onEdit }: ClientColumnOptions): DataTableColumn<ClientRecord>[] {
  return column.columns([
    column.accessor((client) => clientDisplayName(client), {
      id: 'name',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Client" />,
      enableHiding: false,
      meta: { label: 'Client', className: 'min-w-48' },
      cell: ({ row }) => {
        const client = row.original
        return (
          <div className="flex min-w-0 flex-col">
            <Link
              to={detailPath.client(client.id)}
              className="truncate font-medium hover:underline focus-visible:underline"
            >
              {clientDisplayName(client)}
            </Link>
            <span className="text-muted-foreground truncate text-xs">
              @{client.discord_username}
            </span>
          </div>
        )
      },
    }),
    column.accessor('status', {
      header: 'Status',
      meta: { label: 'Status' },
      cell: ({ row }) => {
        const pending = row.original.pending_change
        return (
          <div className="flex items-center gap-1.5">
            <StatusBadge kind="clientStatus" value={row.original.status} />
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
    column.accessor((client) => client.owner?.name ?? '', {
      id: 'owner',
      header: 'Owner',
      meta: { label: 'Owner' },
      cell: ({ getValue }) => <span className="whitespace-nowrap">{getValue() || '—'}</span>,
    }),
    column.accessor('lifetime_value', {
      id: 'lifetime_value',
      header: () => <span className="block text-right">Lifetime value</span>,
      meta: { label: 'Lifetime value', className: 'text-right' },
      cell: ({ getValue }) => <MoneyText money={getValue()} />,
    }),
    column.accessor('nurturing_rating', {
      id: 'nurturing_rating',
      enableSorting: true,
      header: ({ column }) => (
        <DataTableColumnHeader column={column} title="Rating" className="ml-auto" />
      ),
      meta: { label: 'Nurturing rating', className: 'text-right' },
      cell: ({ getValue }) => <span className="tabular">{getValue() ?? '—'}</span>,
    }),
    column.accessor('expected_upsell_on', {
      id: 'expected_upsell_on',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Next upsell" />,
      meta: { label: 'Expected upsell' },
      cell: ({ getValue }) => <RelativeTime value={getValue()} display="date" />,
    }),
    column.accessor('created_at', {
      id: 'created_at',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Added" />,
      meta: { label: 'Added' },
      cell: ({ getValue }) => <RelativeTime value={getValue()} className="text-muted-foreground" />,
    }),
    column.display({
      id: 'actions',
      enableHiding: false,
      header: () => <span className="sr-only">Actions</span>,
      meta: { className: 'w-12 text-right' },
      cell: ({ row }) => <ClientRowActions client={row.original} onEdit={onEdit} />,
    }),
  ])
}
