import type { EnumValue } from '@/api/types'
import { labelFor, toneFor, type StatusKind } from '@/lib/enums'
import { TONE_CLASSES } from './tone-classes'
import { cn } from '@/lib/utils'

interface StatusBadgeProps {
  /** Which enum the value belongs to; decides the colour mapping (see lib/enums.ts). */
  kind: StatusKind
  /** The API enum object `{ value, label }`, or a bare value string. */
  value: EnumValue | string | null | undefined
  className?: string
}

/**
 * Consistent coloured badge for enum values (lead stage, account standing, payment status...).
 *
 *   <StatusBadge kind="leadStage" value={lead.stage} />
 */
export function StatusBadge({ kind, value, className }: StatusBadgeProps) {
  if (!value) return null
  const raw = typeof value === 'string' ? value : value.value
  const label = typeof value === 'string' ? labelFor(kind, value) : value.label
  const tone = toneFor(kind, raw)

  return (
    <span
      data-tone={tone}
      className={cn(
        'inline-flex h-5.5 items-center gap-1.5 rounded-full px-2 text-xs font-medium whitespace-nowrap',
        TONE_CLASSES[tone],
        className,
      )}
    >
      <span aria-hidden="true" className="size-1.5 rounded-full bg-(--dot)" />
      {label}
    </span>
  )
}
