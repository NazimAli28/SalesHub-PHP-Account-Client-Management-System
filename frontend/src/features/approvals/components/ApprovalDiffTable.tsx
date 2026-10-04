import { ArrowRightIcon } from 'lucide-react'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { cn } from '@/lib/utils'
import type { ApprovalDiffRow } from '../api'
import { formatDiffValue, humanizeField, isChanged } from '../format'

interface ApprovalDiffTableProps {
  rows: ApprovalDiffRow[]
  /** After approval the "after" column shows what was actually applied. */
  applied: boolean
}

/** Side-by-side current -> proposed values; changed rows are highlighted. */
export function ApprovalDiffTable({ rows, applied }: ApprovalDiffTableProps) {
  return (
    <Table aria-label="Proposed changes">
      <TableHeader>
        <TableRow>
          <TableHead className="w-1/4">Field</TableHead>
          <TableHead>{applied ? 'Before' : 'Current'}</TableHead>
          <TableHead className="w-6" aria-hidden="true" />
          <TableHead>{applied ? 'Applied' : 'Proposed'}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {rows.map((row) => {
          const changed = isChanged(row)
          return (
            <TableRow
              key={row.field}
              data-changed={changed}
              className={cn(changed && 'bg-amber-50/70 dark:bg-amber-950/20')}
            >
              <TableCell className="font-medium">{humanizeField(row.field)}</TableCell>
              <TableCell
                className={cn(
                  'whitespace-pre-wrap',
                  changed ? 'text-muted-foreground line-through decoration-1' : '',
                )}
              >
                {formatDiffValue(row.field, row.before)}
              </TableCell>
              <TableCell aria-hidden="true" className="text-muted-foreground px-0">
                <ArrowRightIcon className="size-3.5" />
              </TableCell>
              <TableCell className={cn('whitespace-pre-wrap', changed && 'font-medium')}>
                {formatDiffValue(row.field, row.after)}
                {changed ? <span className="sr-only"> (changed)</span> : null}
              </TableCell>
            </TableRow>
          )
        })}
      </TableBody>
    </Table>
  )
}
