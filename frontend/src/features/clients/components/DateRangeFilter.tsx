import { CalendarRangeIcon, XIcon } from 'lucide-react'
import type { DataTableParams } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { formatDate } from '@/lib/format'

interface DateRangeFilterProps {
  state: DataTableParams
  title: string
  /** API filter names, e.g. `created_from` / `created_to` (both must be in the list's `filterKeys`). */
  fromKey: string
  toKey: string
}

/**
 * From/to date filter that writes two URL filters. Each input updates its own key (one URL
 * change per event), so the two values never overwrite each other.
 */
export function DateRangeFilter({ state, title, fromKey, toKey }: DateRangeFilterProps) {
  const from = state.params.filters[fromKey]?.[0] ?? ''
  const to = state.params.filters[toKey]?.[0] ?? ''
  const summary = [from && formatDate(from), to && formatDate(to)].filter(Boolean).join(' – ')

  const field = (id: string, label: string, key: string, value: string) => (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      <div className="flex gap-1">
        <Input
          id={id}
          type="date"
          value={value}
          onChange={(event) => state.setFilter(key, event.target.value ? [event.target.value] : [])}
        />
        {value ? (
          <Button
            type="button"
            variant="ghost"
            size="icon"
            aria-label={`Clear ${label.toLowerCase()} date`}
            onClick={() => state.setFilter(key, [])}
          >
            <XIcon aria-hidden="true" />
          </Button>
        ) : null}
      </div>
    </div>
  )

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" className="border-dashed">
          <CalendarRangeIcon aria-hidden="true" />
          {title}
          {summary ? (
            <Badge variant="secondary" className="hidden lg:inline-flex">
              {summary}
            </Badge>
          ) : null}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-64 space-y-3">
        {field(`${fromKey}-input`, 'From', fromKey, from)}
        {field(`${toKey}-input`, 'To', toKey, to)}
      </PopoverContent>
    </Popover>
  )
}
