import {
  columnVisibilityFeature,
  createColumnHelper as createTanStackColumnHelper,
  rowSelectionFeature,
  rowSortingFeature,
  tableFeatures,
  type CellData,
  type ColumnDef,
  type RowData,
  type TableFeatures,
} from '@tanstack/react-table'
import { Checkbox } from '@/components/ui/checkbox'

/**
 * The TanStack Table v9 features every DataTable uses. Sorting, paging and filtering happen on
 * the server, so no client row models are registered; sorting is only used for header state.
 */
export const dataTableFeatures = tableFeatures({
  rowSortingFeature,
  columnVisibilityFeature,
  rowSelectionFeature,
})

export type DataTableFeatures = typeof dataTableFeatures
export type DataTableColumn<TData extends RowData> = ColumnDef<DataTableFeatures, TData, any>

declare module '@tanstack/react-table' {
  // Extra per-column options read by <DataTable>.
  interface ColumnMeta<
    in out TFeatures extends TableFeatures,
    in out TData extends RowData,
    TValue extends CellData = CellData,
  > {
    /** Name shown in the "Columns" menu (defaults to the column id). */
    label?: string
    /** Classes for the header and body cells, e.g. `'text-right'` or `'w-12'`. */
    className?: string
  }
}

/**
 * Column helper bound to the DataTable features. Use it exactly like TanStack's helper:
 *
 *   const column = createColumnHelper<Lead>()
 *   export const leadColumns = column.columns([
 *     column.accessor('contacted_on', {
 *       id: 'contacted_on',          // = the API sort field when the column is sortable
 *       enableSorting: true,         // columns are NOT sortable unless you opt in
 *       header: ({ column }) => <DataTableColumnHeader column={column} title="Contacted" />,
 *       cell: ({ getValue }) => <RelativeTime value={getValue()} display="date" />,
 *       meta: { label: 'Contacted' },
 *     }),
 *   ])
 */
export function createColumnHelper<TData extends RowData>() {
  return createTanStackColumnHelper<DataTableFeatures, TData>()
}

/** Checkbox column for row selection. Put it first in `columns` and pass `enableRowSelection`. */
export function createSelectColumn<TData extends RowData>(): DataTableColumn<TData> {
  return {
    id: 'select',
    enableSorting: false,
    enableHiding: false,
    meta: { className: 'w-10' },
    header: ({ table }) => (
      <Checkbox
        aria-label="Select all rows on this page"
        checked={
          table.getIsAllPageRowsSelected()
            ? true
            : table.getIsSomePageRowsSelected()
              ? 'indeterminate'
              : false
        }
        onCheckedChange={(value) => table.toggleAllPageRowsSelected(value === true)}
      />
    ),
    cell: ({ row }) => (
      <Checkbox
        aria-label="Select row"
        checked={row.getIsSelected()}
        disabled={!row.getCanSelect()}
        onCheckedChange={(value) => row.toggleSelected(value === true)}
      />
    ),
  }
}
