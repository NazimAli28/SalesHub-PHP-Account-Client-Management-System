import { formatDate, formatDateTime, formatRelative, parseDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'

interface RelativeTimeProps {
  /** ISO timestamp or `YYYY-MM-DD` date from the API. */
  value: string | null | undefined
  /** `relative` = "3 days ago", `date` = "Oct 4, 2026". The other form shows in a tooltip. */
  display?: 'relative' | 'date'
  placeholder?: string
  className?: string
}

/** A semantic `<time>` with the exact date in a tooltip. */
export function RelativeTime({
  value,
  display = 'relative',
  placeholder = '—',
  className,
}: RelativeTimeProps) {
  const date = parseDate(value)
  if (!value || !date) {
    return <span className={cn('text-muted-foreground', className)}>{placeholder}</span>
  }
  const isDateOnly = value.length === 10
  const exact = isDateOnly ? formatDate(value) : formatDateTime(value)
  const text = display === 'relative' ? formatRelative(value) : formatDate(value)
  const tooltip = display === 'relative' ? exact : formatRelative(value)

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <time dateTime={value} className={cn('whitespace-nowrap', className)} tabIndex={0}>
          {text}
        </time>
      </TooltipTrigger>
      <TooltipContent>{tooltip}</TooltipContent>
    </Tooltip>
  )
}
