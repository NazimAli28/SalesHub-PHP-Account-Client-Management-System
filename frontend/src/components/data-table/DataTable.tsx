import { useEffect, useMemo, useState, type ReactNode } from 'react'
import {
  functionalUpdate,
  useTable,
  type ColumnVisibilityState,
  type Row,
  type SortingState,
  type Updater,
} from '@tanstack/react-table'
import { FilterXIcon, SearchXIcon, XIcon } from 'lucide-react'
import { formatSort, parseSort } from '@/api/list-params'
import type { Paginated } from '@/api/types'
import { EmptyState } from '@/components/layout/EmptyState'
import { ErrorState } from '@/components/layout/ErrorState'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { cn } from '@/lib/utils'
import { dataTableFeatures, type DataTableColumn } from './columns'
import { DataTablePagination } from './DataTablePagination'
import { DataTableSearch } from './DataTableSearch'
import { DataTableViewOptions } from './DataTableViewOptions'
import type { DataTableParams } from './use-data-table-params'

/** The parts of a TanStack `useQuery` result the table needs. */
export interface DataTableQuery<TData> {
  data: Paginated<TData> | undefined
  isPending: boolean
  isError: boolean
  isFetching: boolean
  error: unknown
  refetch: () => unknown
}

export interface DataTableProps<TData extends { id: number | string }> {
  /** Accessible name of the table, e.g. "Leads". */
  label: string
  columns: DataTableColumn<TData>[]
  query: DataTableQuery<TData>
  /** URL-backed list state from `useDataTableParams()`. */
  state: DataTableParams
  /** Filter controls shown next to the search box (e.g. `<DataTableFacetedFilter>`s). */
  filters?: ReactNode
  /** Set to `false` to hide the search box. */
  searchPlaceholder?: string | false
  /** Shown when the list is empty and no filters are active. */
  emptyState?: ReactNode
  /** Adds checkbox selection. Put `createSelectColumn()` first in `columns`. */
  enableRowSelection?: boolean
  /** Rendered in a bar above the table while rows are selected. */
  bulkActions?: (rows: TData[], clearSelection: () => void) => ReactNode
  initialColumnVisibility?: ColumnVisibilityState
  /** Skeleton rows while the first page loads. Defaults to the page size (max 10). */
  skeletonRows?: number
}

const EMPTY_ROWS: never[] = []

/**
 * Server-driven data table: pagination, sorting, search and filters live in the URL
 * (`useDataTableParams`), the query hook fetches that page, and this component renders it.
 * See docs/frontend/screen-guide.md for a full example (the Leads list).
 */
export function DataTable<TData extends { id: number | string }>({
  label,
  columns,
  query,
  state,
  filters,
  searchPlaceholder = 'Search…',
  emptyState,
  enableRowSelection = false,
  bulkActions,
  initialColumnVisibility,
  skeletonRows,
}: DataTableProps<TData>) {
  const rows = query.data?.data ?? (EMPTY_ROWS as TData[])
  const sortParam = state.params.sort
  const sorting = useMemo<SortingState>(() => {
    const sort = parseSort(sortParam)
    return sort ? [sort] : []
  }, [sortParam])
  const [columnVisibility, setColumnVisibility] = useState<ColumnVisibilityState>(
    initialColumnVisibility ?? {},
  )

  const { setSort } = state
  const table = useTable({
    features: dataTableFeatures,
    columns,
    data: rows,
    getRowId: (row) => String(row.id),
    state: { sorting, columnVisibility },
    onSortingChange: (updater: Updater<SortingState>) => {
      const next = functionalUpdate(updater, sorting)
      setSort(formatSort(next[0] ?? null))
    },
    onColumnVisibilityChange: setColumnVisibility,
    manualSorting: true,
    enableMultiSort: false,
    // Toggle between ascending and descending; the endpoint default applies until a header is clicked.
    enableSortingRemoval: false,
    // Columns opt in with `enableSorting: true` (their id must be an API sort field).
    defaultColumn: { enableSorting: false },
    enableRowSelection,
  })

  // A new page, sort or filter means different rows: drop the old selection.
  const { resetRowSelection } = table
  useEffect(() => {
    resetRowSelection()
  }, [query.data, resetRowSelection])

  const selectedRows = table
    .getSelectedRowModel()
    .rows.map((row: Row<typeof dataTableFeatures, TData>) => row.original)
  const visibleColumnCount = table.getVisibleLeafColumns().length
  const showSkeleton = query.isPending
  const isEmpty = !query.isPending && !query.isError && rows.length === 0
  const isRefetching = query.isFetching && !query.isPending

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        {searchPlaceholder !== false ? (
          <DataTableSearch state={state} placeholder={searchPlaceholder} />
        ) : null}
        {filters ? <div className="flex flex-wrap items-center gap-2">{filters}</div> : null}
        {state.hasActiveFilters ? (
          <Button variant="ghost" onClick={state.resetFilters}>
            Reset
            <XIcon aria-hidden="true" />
          </Button>
        ) : null}
        <DataTableViewOptions table={table} />
      </div>

      {bulkActions && selectedRows.length > 0 ? (
        <div
          role="region"
          aria-label="Bulk actions"
          className="border-primary/20 bg-primary/5 flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2"
        >
          <span className="text-sm font-medium">{selectedRows.length} selected</span>
          <div className="ml-auto flex flex-wrap items-center gap-2">
            {bulkActions(selectedRows, () => table.resetRowSelection())}
            <Button variant="ghost" size="sm" onClick={() => table.resetRowSelection()}>
              Clear selection
            </Button>
          </div>
        </div>
      ) : null}

      <div className="bg-card relative max-h-[min(70vh,48rem)] overflow-auto rounded-xl border">
        <table
          data-slot="table"
          aria-label={label}
          aria-busy={query.isFetching}
          className="w-full caption-bottom text-sm"
        >
          <TableHeader className="bg-muted/80 supports-[backdrop-filter]:bg-muted/60 sticky top-0 z-10 backdrop-blur">
            {table.getHeaderGroups().map((group) => (
              <TableRow key={group.id} className="hover:bg-transparent">
                {group.headers.map((header) => {
                  const sorted = header.column.getIsSorted()
                  return (
                    <TableHead
                      key={header.id}
                      colSpan={header.colSpan}
                      aria-sort={
                        header.column.getCanSort()
                          ? sorted === 'asc'
                            ? 'ascending'
                            : sorted === 'desc'
                              ? 'descending'
                              : 'none'
                          : undefined
                      }
                      className={cn(
                        'text-muted-foreground h-10 px-3',
                        header.column.columnDef.meta?.className,
                      )}
                    >
                      {header.isPlaceholder ? null : <table.FlexRender header={header} />}
                    </TableHead>
                  )
                })}
              </TableRow>
            ))}
          </TableHeader>

          <TableBody className={cn('transition-opacity', isRefetching && 'opacity-60')}>
            {showSkeleton
              ? Array.from(
                  { length: skeletonRows ?? Math.min(state.params.pageSize, 10) },
                  (_, index) => (
                    <TableRow key={`skeleton-${index}`} aria-hidden="true">
                      {Array.from({ length: visibleColumnCount }, (_, cell) => (
                        <TableCell key={cell} className="px-3 py-3">
                          <Skeleton className="h-4 w-full max-w-36" />
                        </TableCell>
                      ))}
                    </TableRow>
                  ),
                )
              : null}

            {query.isError ? (
              <TableRow className="hover:bg-transparent">
                <TableCell colSpan={visibleColumnCount} className="p-0">
                  <ErrorState
                    title={`We could not load ${label.toLowerCase()}`}
                    error={query.error}
                    onRetry={() => void query.refetch()}
                  />
                </TableCell>
              </TableRow>
            ) : null}

            {isEmpty ? (
              <TableRow className="hover:bg-transparent">
                <TableCell colSpan={visibleColumnCount} className="p-0">
                  {state.hasActiveFilters ? (
                    <EmptyState
                      icon={SearchXIcon}
                      title="No matches"
                      description="Nothing matches your search and filters."
                      action={
                        <Button variant="outline" size="sm" onClick={state.resetFilters}>
                          <FilterXIcon aria-hidden="true" />
                          Clear filters
                        </Button>
                      }
                    />
                  ) : (
                    (emptyState ?? <EmptyState title={`No ${label.toLowerCase()} yet`} />)
                  )}
                </TableCell>
              </TableRow>
            ) : null}

            {!showSkeleton && !query.isError
              ? table.getRowModel().rows.map((row) => (
                  <TableRow key={row.id} data-state={row.getIsSelected() ? 'selected' : undefined}>
                    {row.getVisibleCells().map((cell) => (
                      <TableCell
                        key={cell.id}
                        className={cn('px-3 py-2.5', cell.column.columnDef.meta?.className)}
                      >
                        <table.FlexRender cell={cell} />
                      </TableCell>
                    ))}
                  </TableRow>
                ))
              : null}
          </TableBody>
        </table>
      </div>

      <DataTablePagination
        meta={query.data?.meta}
        state={state}
        selectedCount={selectedRows.length}
      />
    </div>
  )
}
