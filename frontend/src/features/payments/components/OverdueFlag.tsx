import { cn } from '@/lib/utils'

/** Red "Overdue" marker used wherever an installment is past due. */
export function OverdueFlag({ className }: { className?: string }) {
  return (
    <span
      className={cn(
        'bg-destructive/10 text-destructive inline-flex h-5 items-center rounded-full px-2 text-xs font-medium',
        className,
      )}
    >
      Overdue
    </span>
  )
}
