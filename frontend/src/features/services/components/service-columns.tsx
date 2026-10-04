import {
  createColumnHelper,
  DataTableColumnHeader,
  type DataTableColumn,
} from '@/components/data-table'
import { MoneyText } from '@/components/data-display/MoneyText'
import { Badge } from '@/components/ui/badge'
import { ActiveBadge } from '@/features/users/components/ActiveBadge'
import type { Service } from '../api'
import { ServiceRowActions } from './ServiceRowActions'

const column = createColumnHelper<Service>()

export function getServiceColumns({
  onEdit,
  showActions,
}: {
  onEdit: (service: Service) => void
  showActions: boolean
}): DataTableColumn<Service>[] {
  const columns = column.columns([
    column.accessor('name', {
      id: 'name',
      enableSorting: true,
      enableHiding: false,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Service" />,
      meta: { label: 'Service', className: 'min-w-56' },
      cell: ({ row }) => (
        <div className="flex min-w-0 flex-col">
          <span className="truncate font-medium">{row.original.name}</span>
          {row.original.description ? (
            <span className="text-muted-foreground max-w-96 truncate text-xs">
              {row.original.description}
            </span>
          ) : null}
        </div>
      ),
    }),
    column.accessor((service) => service.category?.label ?? '', {
      id: 'category',
      enableSorting: true,
      header: ({ column }) => <DataTableColumnHeader column={column} title="Category" />,
      meta: { label: 'Category' },
      cell: ({ getValue }) =>
        getValue() ? (
          <Badge variant="secondary">{getValue()}</Badge>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    }),
    column.accessor('base_price', {
      id: 'base_price_cents',
      enableSorting: true,
      header: ({ column }) => (
        <DataTableColumnHeader column={column} title="Base price" className="ml-auto" />
      ),
      meta: { label: 'Base price', className: 'text-right' },
      cell: ({ getValue }) => <MoneyText money={getValue()} />,
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
          cell: ({ row }) => <ServiceRowActions service={row.original} onEdit={onEdit} />,
        }),
      ]
    : columns
}
