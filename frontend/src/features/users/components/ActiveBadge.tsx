import { TONE_CLASSES } from '@/components/data-display/tone-classes'
import { cn } from '@/lib/utils'

/** Active / inactive pill using the shared tone colours (success vs muted). */
export function ActiveBadge({
  active,
  activeLabel = 'Active',
  inactiveLabel = 'Inactive',
}: {
  active: boolean
  activeLabel?: string
  inactiveLabel?: string
}) {
  const tone = active ? 'success' : 'muted'
  return (
    <span
      data-tone={tone}
      className={cn(
        'inline-flex h-5.5 items-center gap-1.5 rounded-full px-2 text-xs font-medium whitespace-nowrap',
        TONE_CLASSES[tone],
      )}
    >
      <span aria-hidden="true" className="size-1.5 rounded-full bg-(--dot)" />
      {active ? activeLabel : inactiveLabel}
    </span>
  )
}
