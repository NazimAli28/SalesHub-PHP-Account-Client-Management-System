import type { User } from '@/api/types'
import {
  createColumnHelper,
  DataTableColumnHeader,
  type DataTableColumn,
} from '@/components/data-table'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { initials } from '@/lib/format'
import { roleLabel } from '@/lib/roles'
import { ActiveBadge } from './ActiveBadge'
import { UserRowActions } from './UserRowActions'

const column = createColumnHelper<User>()

export function getUserColumns({
  onEdit,
  currentUserId,
}: {
  onEdit: (user: User) => void
  currentUserId: number | undefined
}): DataTableColumn<User>[] {
  return column.columns([
    column.accessor('name', {
      id: 'name',
      enableSorting: true,
      enableHiding: false,
      header: ({ column }) => <DataTableColumnHeader column={column} title="User" />,
      meta: { label: 'User', className: 'min-w-56' },
      cell: ({ row }) => {
        const user = row.original
        return (
          <div className="flex min-w-0 items-center gap-3">
            <Avatar size="default">
              {user.avatar_url ? <AvatarImage src={user.avatar_url} alt="" /> : null}
              <AvatarFallback>{initials(user.name)}</AvatarFallback>
            </Avatar>
            <div className="flex min-w-0 flex-col">
              <span className="truncate font-medium">
                {user.name}
                {user.id === currentUserId ? (
                  <span className="text-muted-foreground ml-1.5 text-xs font-normal">(you)</span>
                ) : null}
              </span>
              <span className="text-muted-foreground truncate text-xs">{user.email}</span>
            </div>
          </div>
        )
      },
    }),
    column.accessor('username', {
      id: 'username',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Username" />,
      meta: { label: 'Username' },
      cell: ({ getValue }) => <span className="text-muted-foreground">@{getValue()}</span>,
    }),
    column.accessor((user) => user.roles[0] ?? '', {
      id: 'role',
      header: 'Role',
      meta: { label: 'Role' },
      cell: ({ getValue }) =>
        getValue() ? (
          <Badge variant="secondary">{roleLabel(getValue())}</Badge>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    }),
    column.accessor((user) => user.team?.name ?? '', {
      id: 'team',
      header: 'Team',
      meta: { label: 'Team' },
      cell: ({ getValue }) => getValue() || <span className="text-muted-foreground">—</span>,
    }),
    column.accessor((user) => user.workstation?.code ?? '', {
      id: 'workstation',
      header: 'Workstation',
      meta: { label: 'Workstation' },
      cell: ({ getValue }) => getValue() || <span className="text-muted-foreground">—</span>,
    }),
    column.accessor('last_login_at', {
      id: 'last_login_at',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Last login" />,
      meta: { label: 'Last login' },
      cell: ({ getValue }) => (
        <RelativeTime value={getValue()} placeholder="Never" className="text-muted-foreground" />
      ),
    }),
    column.accessor('is_active', {
      id: 'is_active',
      header: 'Status',
      meta: { label: 'Status' },
      cell: ({ getValue }) => <ActiveBadge active={getValue()} />,
    }),
    column.display({
      id: 'actions',
      enableHiding: false,
      header: () => <span className="sr-only">Actions</span>,
      meta: { className: 'w-12 text-right' },
      cell: ({ row }) => <UserRowActions user={row.original} onEdit={onEdit} />,
    }),
  ])
}
