import { CalendarRangeIcon } from 'lucide-react'
import type { DataTableParams } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Separator } from '@/components/ui/separator'

/**
 * Batch date range filter, stored in the URL as `batch_from` / `batch_to`
 * (API: `filter[batch_from]`, `filter[batch_to]`, both inclusive).
 */
export function BatchDateFilter({ state }: { state: DataTableParams }) {
  const from = state.params.filters.batch_from?.[0] ?? ''
  const to = state.params.filters.batch_to?.[0] ?? ''
  const active = [from, to].filter(Boolean).length

  const change = (key: 'batch_from' | 'batch_to', value: string) =>
    state.setFilter(key, value ? [value] : [])

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" className="border-dashed">
          <CalendarRangeIcon aria-hidden="true" />
          Batch date
          {active > 0 ? (
            <>
              <Separator orientation="vertical" className="mx-0.5 h-4" />
              <Badge variant="secondary">
                {from || '…'} to {to || '…'}
              </Badge>
            </>
          ) : null}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-64 space-y-3" align="start">
        <div className="space-y-1.5">
          <Label htmlFor="batch-from">Batch from</Label>
          <Input
            id="batch-from"
            type="date"
            value={from}
            max={to || undefined}
            onChange={(event) => change('batch_from', event.target.value)}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="batch-to">Batch to</Label>
          <Input
            id="batch-to"
            type="date"
            value={to}
            min={from || undefined}
            onChange={(event) => change('batch_to', event.target.value)}
          />
        </div>
      </PopoverContent>
    </Popover>
  )
}
