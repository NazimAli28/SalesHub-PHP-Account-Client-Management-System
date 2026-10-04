import {
  createColumnHelper,
  DataTableColumnHeader,
  type DataTableColumn,
} from '@/components/data-table'
import type { Team } from '../api'
import { TeamRowActions } from './TeamRowActions'

const column = createColumnHelper<Team>()

interface TeamColumnOptions {
  onEdit: (team: Team) => void
  onShowMembers: (team: Team) => void
}

export function getTeamColumns({
  onEdit,
  onShowMembers,
}: TeamColumnOptions): DataTableColumn<Team>[] {
  return column.columns([
    column.accessor('name', {
      id: 'name',
      enableSorting: true,
      enableHiding: false,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Team" />,
      meta: { label: 'Team', className: 'min-w-40' },
      cell: ({ getValue }) => <span className="font-medium">{getValue()}</span>,
    }),
    column.accessor((team) => team.team_lead?.name ?? '', {
      id: 'team_lead',
      header: 'Team lead',
      meta: { label: 'Team lead' },
      cell: ({ getValue }) => (
        <span className="whitespace-nowrap">
          {getValue() || <span className="text-muted-foreground">—</span>}
        </span>
      ),
    }),
    column.accessor('floor', {
      id: 'floor',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Floor" />,
      meta: { label: 'Floor' },
      cell: ({ getValue }) => <span className="tabular">{getValue()}</span>,
    }),
    column.accessor((team) => team.shift?.label ?? '', {
      id: 'shift',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Shift" />,
      meta: { label: 'Shift' },
    }),
    column.accessor('members_count', {
      id: 'members_count',
      header: () => <span className="block text-right">Members</span>,
      meta: { label: 'Members', className: 'text-right' },
      cell: ({ getValue }) => <span className="tabular">{getValue() ?? 0}</span>,
    }),
    column.accessor('workstations_count', {
      id: 'workstations_count',
      header: () => <span className="block text-right">Workstations</span>,
      meta: { label: 'Workstations', className: 'text-right' },
      cell: ({ getValue }) => <span className="tabular">{getValue() ?? 0}</span>,
    }),
    column.display({
      id: 'actions',
      enableHiding: false,
      header: () => <span className="sr-only">Actions</span>,
      meta: { className: 'w-12 text-right' },
      cell: ({ row }) => (
        <TeamRowActions team={row.original} onEdit={onEdit} onShowMembers={onShowMembers} />
      ),
    }),
  ])
}
