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
import { OverdueFlag } from '@/features/payments/components/OverdueFlag'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { formatRelative } from '@/lib/format'
import type { Order } from '../types'
import { OrderRowActions } from './OrderRowActions'

const column = createColumnHelper<Order>()

/** Columns for the Orders table. */
export function getOrderColumns(): DataTableColumn<Order>[] {
  return column.columns([
    column.accessor('order_number', {
      id: 'order_number',
      enableSorting: true,
      enableHiding: false,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Order" />,
      meta: { label: 'Order' },
      cell: ({ row }) => (
        <Link
          to={detailPath.order(row.original.id)}
          className="font-medium whitespace-nowrap hover:underline focus-visible:underline"
        >
          {row.original.order_number}
        </Link>
      ),
    }),
    column.accessor((order) => order.client?.name ?? order.client?.discord_username ?? '', {
      id: 'client',
      header: 'Client',
      meta: { label: 'Client', className: 'min-w-40' },
      cell: ({ row }) => {
        const client = row.original.client
        if (!client) return <span className="text-muted-foreground">#{row.original.client_id}</span>
        return (
          <div className="flex min-w-0 flex-col">
            <Link
              to={detailPath.client(client.id)}
              className="truncate font-medium hover:underline focus-visible:underline"
            >
              {client.name ?? client.discord_username}
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
            <StatusBadge kind="orderStatus" value={row.original.status} />
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
    column.accessor('total', {
      id: 'total_cents',
      enableSorting: true,
      header: ({ column }) => (
        <DataTableColumnHeader column={column} title="Total" className="ml-auto" />
      ),
      meta: { label: 'Total', className: 'text-right' },
      cell: ({ getValue }) => <MoneyText money={getValue()} />,
    }),
    column.accessor('amount_paid', {
      header: () => <span className="block text-right">Paid</span>,
      meta: { label: 'Paid', className: 'text-right' },
      cell: ({ getValue }) => <MoneyText money={getValue()} />,
    }),
    column.accessor('balance', {
      header: () => <span className="block text-right">Balance</span>,
      meta: { label: 'Balance', className: 'text-right' },
      cell: ({ row }) => (
        <div className="flex items-center justify-end gap-2">
          {row.original.overdue_payments_count > 0 ? <OverdueFlag /> : null}
          <MoneyText money={row.original.balance} />
        </div>
      ),
    }),
    column.accessor((order) => order.owner?.name ?? '', {
      id: 'owner',
      header: 'Owner',
      meta: { label: 'Owner' },
      cell: ({ getValue }) => <span className="whitespace-nowrap">{getValue() || '—'}</span>,
    }),
    column.accessor('ordered_on', {
      id: 'ordered_on',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Ordered" />,
      meta: { label: 'Ordered' },
      cell: ({ getValue }) => <RelativeTime value={getValue()} display="date" />,
    }),
    column.display({
      id: 'actions',
      enableHiding: false,
      header: () => <span className="sr-only">Actions</span>,
      meta: { className: 'w-12 text-right' },
      cell: ({ row }) => <OrderRowActions order={row.original} />,
    }),
  ])
}
