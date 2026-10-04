import type { Column, RowData } from '@tanstack/react-table'
import { ArrowDownIcon, ArrowUpDownIcon, ArrowUpIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import type { DataTableFeatures } from './columns'

interface DataTableColumnHeaderProps<TData extends RowData, TValue> {
  column: Column<DataTableFeatures, TData, TValue>
  title: string
  className?: string
}

/**
 * Header with a sort toggle for sortable columns (`enableSorting: true`); plain text otherwise.
 * Clicking toggles ascending / descending.
 * `aria-sort` is set on the `<th>` by DataTable.
 */
export function DataTableColumnHeader<TData extends RowData, TValue>({
  column,
  title,
  className,
}: DataTableColumnHeaderProps<TData, TValue>) {
  if (!column.getCanSort()) {
    return <span className={cn('font-medium', className)}>{title}</span>
  }

  const sorted = column.getIsSorted()
  const Icon = sorted === 'asc' ? ArrowUpIcon : sorted === 'desc' ? ArrowDownIcon : ArrowUpDownIcon
  const next = sorted === 'asc' ? 'descending' : 'ascending'

  return (
    <Button
      variant="ghost"
      size="sm"
      className={cn('data-[sorted=true]:text-foreground -ml-2.5 h-7 px-2.5 font-medium', className)}
      data-sorted={sorted !== false}
      onClick={column.getToggleSortingHandler()}
      aria-label={`${title}: sort ${next}`}
    >
      {title}
      <Icon
        className={cn('size-3.5', sorted === false && 'text-muted-foreground/70')}
        aria-hidden="true"
      />
    </Button>
  )
}
