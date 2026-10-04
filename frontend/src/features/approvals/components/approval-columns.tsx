import { Link } from 'react-router'
import { detailPath } from '@/app/paths'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { StatusBadge } from '@/components/data-display/StatusBadge'
import {
  createColumnHelper,
  createSelectColumn,
  DataTableColumnHeader,
  type DataTableColumn,
} from '@/components/data-table'
import type { ApprovalItem } from '../api'
import { humanizeField, summarize, typeMeta } from '../format'

const column = createColumnHelper<ApprovalItem>()

/** Column definitions for the approvals queue. Build them with useMemo in the page. */
export function getApprovalColumns({
  selectable,
}: {
  selectable: boolean
}): DataTableColumn<ApprovalItem>[] {
  const columns = column.columns([
    column.display({
      id: 'summary',
      enableHiding: false,
      header: () => 'Request',
      meta: { label: 'Request', className: 'min-w-56' },
      cell: ({ row }) => {
        const approval = row.original
        const Icon = typeMeta(approval).icon
        const fields = approval.fields.slice(0, 3).map(humanizeField).join(', ')
        return (
          <div className="flex min-w-0 items-center gap-3">
            <span className="bg-muted text-muted-foreground flex size-8 shrink-0 items-center justify-center rounded-md">
              <Icon className="size-4" aria-hidden="true" />
              <span className="sr-only">{typeMeta(approval).label}</span>
            </span>
            <div className="flex min-w-0 flex-col">
              <Link
                to={detailPath.approval(approval.id)}
                className="truncate font-medium hover:underline"
              >
                {summarize(approval)}
              </Link>
              {fields ? (
                <span className="text-muted-foreground truncate text-xs">{fields}</span>
              ) : null}
            </div>
          </div>
        )
      },
    }),
    column.accessor((approval) => approval.requester?.name ?? '', {
      id: 'requester',
      header: () => 'Requester',
      meta: { label: 'Requester' },
      cell: ({ getValue }) => <span className="whitespace-nowrap">{getValue() || '—'}</span>,
    }),
    column.accessor('created_at', {
      id: 'created_at',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Requested" />,
      meta: { label: 'Requested' },
      cell: ({ getValue }) => <RelativeTime value={getValue()} />,
    }),
    column.accessor('status', {
      id: 'status',
      header: () => 'Status',
      meta: { label: 'Status' },
      cell: ({ getValue }) => <StatusBadge kind="approvalStatus" value={getValue()} />,
    }),
  ])

  return selectable ? [createSelectColumn<ApprovalItem>(), ...columns] : columns
}
