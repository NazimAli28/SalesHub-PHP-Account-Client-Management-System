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
import { cn } from '@/lib/utils'
import type { Payment } from '../types'
import { OverdueFlag } from './OverdueFlag'
import { PaymentRowActions } from './PaymentRowActions'

const column = createColumnHelper<Payment>()

/** Columns for the global Payments table. */
export function getPaymentColumns(): DataTableColumn<Payment>[] {
  return column.columns([
    column.accessor('due_date', {
      id: 'due_date',
      enableSorting: true,
      enableHiding: false,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Due" />,
      meta: { label: 'Due date' },
      cell: ({ row }) => (
        <span className={cn(row.original.is_overdue && 'text-destructive font-medium')}>
          <RelativeTime value={row.original.due_date} display="date" />
        </span>
      ),
    }),
    column.accessor((payment) => payment.order?.order_number ?? '', {
      id: 'order',
      header: 'Order',
      meta: { label: 'Order', className: 'min-w-40' },
      cell: ({ row }) => {
        const payment = row.original
        const client = payment.order?.client
        return (
          <div className="flex min-w-0 flex-col">
            <Link
              to={detailPath.order(payment.order_id)}
              className="truncate font-medium hover:underline focus-visible:underline"
            >
              {payment.order?.order_number ?? `Order #${payment.order_id}`}
            </Link>
            {client ? (
              <Link
                to={detailPath.client(client.id)}
                className="text-muted-foreground truncate text-xs hover:underline"
              >
                {client.name ?? client.discord_username}
              </Link>
            ) : null}
          </div>
        )
      },
    }),
    column.accessor('sequence', {
      id: 'sequence',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="#" />,
      meta: { label: 'Installment' },
      cell: ({ getValue }) => <span className="tabular">{getValue()}</span>,
    }),
    column.accessor('amount', {
      id: 'amount_cents',
      enableSorting: true,
      header: ({ column }) => (
        <DataTableColumnHeader column={column} title="Amount" className="ml-auto" />
      ),
      meta: { label: 'Amount', className: 'text-right' },
      cell: ({ getValue }) => <MoneyText money={getValue()} />,
    }),
    column.accessor('status', {
      header: 'Status',
      meta: { label: 'Status' },
      cell: ({ row }) => (
        <div className="flex flex-wrap items-center gap-1.5">
          <StatusBadge kind="paymentStatus" value={row.original.status} />
          {row.original.is_overdue ? <OverdueFlag /> : null}
        </div>
      ),
    }),
    column.accessor('paid_at', {
      id: 'paid_at',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Paid" />,
      meta: { label: 'Paid on' },
      cell: ({ getValue }) => <RelativeTime value={getValue()} display="date" />,
    }),
    column.accessor((payment) => payment.method?.label ?? '', {
      id: 'method',
      header: 'Method',
      meta: { label: 'Method' },
      cell: ({ getValue }) => <span className="whitespace-nowrap">{getValue() || '—'}</span>,
    }),
    column.display({
      id: 'actions',
      enableHiding: false,
      header: () => <span className="sr-only">Actions</span>,
      meta: { className: 'w-12 text-right' },
      cell: ({ row }) => <PaymentRowActions payment={row.original} />,
    }),
  ])
}
