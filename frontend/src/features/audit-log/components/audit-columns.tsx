import { EyeIcon } from 'lucide-react'
import {
  createColumnHelper,
  DataTableColumnHeader,
  type DataTableColumn,
} from '@/components/data-table'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import type { Activity } from '../api'
import { humanizeEvent, humanizeKey } from '../format'

const column = createColumnHelper<Activity>()

export function getAuditColumns({
  onOpen,
}: {
  onOpen: (activity: Activity) => void
}): DataTableColumn<Activity>[] {
  return column.columns([
    column.accessor('created_at', {
      id: 'created_at',
      enableSorting: true,
      enableHiding: false,
      header: ({ column }) => <DataTableColumnHeader column={column} title="When" />,
      meta: { label: 'When' },
      cell: ({ getValue }) => <RelativeTime value={getValue()} />,
    }),
    column.accessor((activity) => activity.causer?.name ?? '', {
      id: 'causer',
      header: 'Who',
      meta: { label: 'Who' },
      cell: ({ getValue }) => (
        <span className="whitespace-nowrap">
          {getValue() || <span className="text-muted-foreground">System</span>}
        </span>
      ),
    }),
    column.accessor((activity) => activity.event, {
      id: 'event',
      header: 'Event',
      meta: { label: 'Event' },
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <span className="font-medium whitespace-nowrap">{humanizeEvent(row.original.event)}</span>
          {row.original.log_name ? (
            <Badge variant="outline">{humanizeKey(row.original.log_name)}</Badge>
          ) : null}
        </div>
      ),
    }),
    column.accessor((activity) => activity.subject?.type ?? '', {
      id: 'subject',
      header: 'Subject',
      meta: { label: 'Subject', className: 'min-w-44' },
      cell: ({ row }) => {
        const subject = row.original.subject
        if (!subject) return <span className="text-muted-foreground">—</span>
        return (
          <div className="flex min-w-0 flex-col">
            <span className="truncate">
              {subject.label ?? `${humanizeKey(subject.type ?? 'record')} #${subject.id}`}
            </span>
            <span className="text-muted-foreground truncate text-xs">
              {humanizeKey(subject.type ?? 'record')} #{subject.id}
            </span>
          </div>
        )
      },
    }),
    column.accessor('description', {
      id: 'description',
      header: 'Description',
      meta: { label: 'Description', className: 'min-w-48' },
      cell: ({ getValue }) => <span className="text-muted-foreground">{getValue()}</span>,
    }),
    column.display({
      id: 'actions',
      enableHiding: false,
      header: () => <span className="sr-only">Details</span>,
      meta: { className: 'w-12 text-right' },
      cell: ({ row }) => (
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={`View details: ${humanizeEvent(row.original.event)}, entry ${row.original.id}`}
          onClick={() => onOpen(row.original)}
        >
          <EyeIcon aria-hidden="true" />
        </Button>
      ),
    }),
  ])
}
