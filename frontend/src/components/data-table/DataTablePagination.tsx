import { useId } from 'react'
import {
  ChevronLeftIcon,
  ChevronRightIcon,
  ChevronsLeftIcon,
  ChevronsRightIcon,
} from 'lucide-react'
import { PAGE_SIZES } from '@/api/list-params'
import type { PaginationMeta } from '@/api/types'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { formatNumber } from '@/lib/format'
import type { DataTableParams } from './use-data-table-params'

interface DataTablePaginationProps {
  meta: PaginationMeta | undefined
  state: DataTableParams
  selectedCount?: number
}

export function DataTablePagination({ meta, state, selectedCount = 0 }: DataTablePaginationProps) {
  const labelId = useId()
  const { page, pageSize } = state.params
  const lastPage = Math.max(meta?.last_page ?? 1, 1)
  const total = meta?.total ?? 0

  return (
    <div className="flex flex-col-reverse gap-3 px-1 sm:flex-row sm:items-center sm:justify-between">
      <p className="text-muted-foreground text-sm" aria-live="polite">
        {selectedCount > 0 ? `${formatNumber(selectedCount)} selected · ` : null}
        {meta && total > 0
          ? `Showing ${formatNumber(meta.from ?? 0)}–${formatNumber(meta.to ?? 0)} of ${formatNumber(total)}`
          : meta
            ? 'No results'
            : ' '}
      </p>

      <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
        <div className="flex items-center gap-2">
          <span id={labelId} className="text-muted-foreground text-sm">
            Rows per page
          </span>
          <Select
            value={String(pageSize)}
            onValueChange={(value) => state.setPageSize(Number(value))}
          >
            <SelectTrigger size="sm" className="w-18" aria-labelledby={labelId}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent align="end">
              {PAGE_SIZES.map((size) => (
                <SelectItem key={size} value={String(size)}>
                  {size}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <nav aria-label="Pagination" className="flex items-center gap-1">
          <span className="tabular mr-2 text-sm font-medium">
            Page {formatNumber(Math.min(page, lastPage))} of {formatNumber(lastPage)}
          </span>
          <Button
            variant="outline"
            size="icon-sm"
            className="hidden sm:inline-flex"
            onClick={() => state.setPage(1)}
            disabled={page <= 1}
            aria-label="First page"
          >
            <ChevronsLeftIcon />
          </Button>
          <Button
            variant="outline"
            size="icon-sm"
            onClick={() => state.setPage(page - 1)}
            disabled={page <= 1}
            aria-label="Previous page"
          >
            <ChevronLeftIcon />
          </Button>
          <Button
            variant="outline"
            size="icon-sm"
            onClick={() => state.setPage(page + 1)}
            disabled={page >= lastPage}
            aria-label="Next page"
          >
            <ChevronRightIcon />
          </Button>
          <Button
            variant="outline"
            size="icon-sm"
            className="hidden sm:inline-flex"
            onClick={() => state.setPage(lastPage)}
            disabled={page >= lastPage}
            aria-label="Last page"
          >
            <ChevronsRightIcon />
          </Button>
        </nav>
      </div>
    </div>
  )
}
