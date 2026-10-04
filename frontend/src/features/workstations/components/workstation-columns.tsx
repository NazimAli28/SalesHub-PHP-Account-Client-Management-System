import {
  createColumnHelper,
  DataTableColumnHeader,
  type DataTableColumn,
} from '@/components/data-table'
import { ActiveBadge } from '@/features/users/components/ActiveBadge'
import type { Workstation } from '../api'
import { WorkstationRowActions } from './WorkstationRowActions'

const column = createColumnHelper<Workstation>()

export function getWorkstationColumns({
  onEdit,
  showActions,
}: {
  onEdit: (workstation: Workstation) => void
  showActions: boolean
}): DataTableColumn<Workstation>[] {
  const columns = column.columns([
    column.accessor('code', {
      id: 'code',
      enableSorting: true,
      enableHiding: false,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Code" />,
      meta: { label: 'Code' },
      cell: ({ getValue }) => <span className="font-medium">{getValue()}</span>,
    }),
    column.accessor('label', {
      id: 'label',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Label" />,
      meta: { label: 'Label' },
      cell: ({ getValue }) => getValue() ?? <span className="text-muted-foreground">—</span>,
    }),
    column.accessor((workstation) => workstation.team?.name ?? '', {
      id: 'team',
      header: 'Team',
      meta: { label: 'Team' },
      cell: ({ getValue }) => getValue() || <span className="text-muted-foreground">—</span>,
    }),
    column.accessor((workstation) => workstation.users?.map((user) => user.name).join(', ') ?? '', {
      id: 'users',
      header: 'Assigned to',
      meta: { label: 'Assigned to', className: 'min-w-40' },
      cell: ({ getValue }) =>
        getValue() || <span className="text-muted-foreground">Unassigned</span>,
    }),
    column.accessor('platform_accounts_count', {
      id: 'platform_accounts_count',
      header: () => <span className="block text-right">Platform accounts</span>,
      meta: { label: 'Platform accounts', className: 'text-right' },
      cell: ({ getValue }) => <span className="tabular">{getValue() ?? 0}</span>,
    }),
    column.accessor('is_active', {
      id: 'is_active',
      header: 'Status',
      meta: { label: 'Status' },
      cell: ({ getValue }) => <ActiveBadge active={getValue()} />,
    }),
  ])

  return showActions
    ? [
        ...columns,
        column.display({
          id: 'actions',
          enableHiding: false,
          header: () => <span className="sr-only">Actions</span>,
          meta: { className: 'w-12 text-right' },
          cell: ({ row }) => <WorkstationRowActions workstation={row.original} onEdit={onEdit} />,
        }),
      ]
    : columns
}
