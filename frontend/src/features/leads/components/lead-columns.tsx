import { ClockIcon } from 'lucide-react'
import type { Lead } from '@/api/types'
import {
  createColumnHelper,
  createSelectColumn,
  DataTableColumnHeader,
  type DataTableColumn,
} from '@/components/data-table'
import { MoneyText } from '@/components/data-display/MoneyText'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { StatusBadge } from '@/components/data-display/StatusBadge'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { formatRelative, toIsoDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import { LeadRowActions } from './LeadRowActions'

const column = createColumnHelper<Lead>()

interface LeadColumnOptions {
  onEdit: (lead: Lead) => void
  selectable: boolean
}

/**
 * Column definitions for the Leads table. Sortable columns use the API sort field as their `id`
 * and opt in with `enableSorting: true`. Build them with useMemo in the page.
 */
export function getLeadColumns({ onEdit, selectable }: LeadColumnOptions): DataTableColumn<Lead>[] {
  const columns = column.columns([
    column.accessor((lead) => lead.client?.name ?? lead.client?.discord_username ?? '', {
      id: 'client',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Client" />,
      enableHiding: false,
      meta: { label: 'Client', className: 'min-w-48' },
      cell: ({ row }) => {
        const client = row.original.client
        if (!client) return <span className="text-muted-foreground">#{row.original.client_id}</span>
        return (
          <div className="flex min-w-0 flex-col">
            <span className="truncate font-medium">{client.name ?? client.discord_username}</span>
            <span className="text-muted-foreground truncate text-xs">
              @{client.discord_username}
            </span>
          </div>
        )
      },
    }),
    column.accessor('stage', {
      header: ({ column }) => <DataTableColumnHeader column={column} title="Stage" />,
      meta: { label: 'Stage' },
      cell: ({ row }) => {
        const pending = row.original.pending_change
        return (
          <div className="flex items-center gap-1.5">
            <StatusBadge kind="leadStage" value={row.original.stage} />
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
    column.accessor('estimated_value', {
      id: 'estimated_value_cents',
      enableSorting: true,
      header: ({ column }) => (
        <DataTableColumnHeader column={column} title="Est. value" className="ml-auto" />
      ),
      meta: { label: 'Estimated value', className: 'text-right' },
      cell: ({ getValue }) => <MoneyText money={getValue()} />,
    }),
    column.accessor((lead) => lead.owner?.name ?? '', {
      id: 'owner',
      header: ({ column }) => <DataTableColumnHeader column={column} title="Owner" />,
      meta: { label: 'Owner' },
      cell: ({ getValue }) => <span className="whitespace-nowrap">{getValue() || '—'}</span>,
    }),
    column.accessor('contacted_on', {
      id: 'contacted_on',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Contacted" />,
      meta: { label: 'Contacted' },
      cell: ({ getValue }) => <RelativeTime value={getValue()} display="date" />,
    }),
    column.accessor('next_follow_up_on', {
      id: 'next_follow_up_on',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Follow-up" />,
      meta: { label: 'Next follow-up' },
      cell: ({ getValue, row }) => {
        const value = getValue()
        const stage = row.original.stage.value
        const overdue =
          Boolean(value) && value! < toIsoDate(new Date()) && stage !== 'won' && stage !== 'lost'
        return (
          <span className={cn(overdue && 'font-medium text-amber-700 dark:text-amber-400')}>
            <RelativeTime value={value} display="date" />
            {overdue ? <span className="sr-only"> (overdue)</span> : null}
          </span>
        )
      },
    }),
    column.accessor('stage_changed_at', {
      id: 'stage_changed_at',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Stage changed" />,
      meta: { label: 'Stage changed' },
      cell: ({ getValue }) => <RelativeTime value={getValue()} className="text-muted-foreground" />,
    }),
    column.display({
      id: 'actions',
      enableHiding: false,
      header: () => <span className="sr-only">Actions</span>,
      meta: { className: 'w-12 text-right' },
      cell: ({ row }) => <LeadRowActions lead={row.original} onEdit={onEdit} />,
    }),
  ])

  return selectable ? [createSelectColumn<Lead>(), ...columns] : columns
}
