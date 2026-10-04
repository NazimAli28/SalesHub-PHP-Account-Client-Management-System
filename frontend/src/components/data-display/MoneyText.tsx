import type { Money } from '@/api/types'
import { formatCents, formatMoney } from '@/lib/format'
import { cn } from '@/lib/utils'

type MoneyTextProps = {
  className?: string
  /** Shown when there is no amount. */
  placeholder?: string
} & (
  | { money: Money | null | undefined; cents?: never; currency?: never }
  | { cents: number | null | undefined; currency?: string; money?: never }
)

/**
 * Formats money with tabular digits so columns line up.
 *
 *   <MoneyText money={lead.estimated_value} />      // API Money object (uses `formatted`)
 *   <MoneyText cents={12345} currency="USD" />      // raw cents, e.g. a form preview
 */
export function MoneyText({ className, placeholder = '—', ...props }: MoneyTextProps) {
  const text =
    'money' in props && props.money !== undefined
      ? formatMoney(props.money)
      : formatCents(props.cents, props.currency)
  if (!text) {
    return (
      <span className={cn('text-muted-foreground', className)}>
        <span aria-hidden="true">{placeholder}</span>
        <span className="sr-only">No amount</span>
      </span>
    )
  }
  return <span className={cn('tabular whitespace-nowrap', className)}>{text}</span>
}
