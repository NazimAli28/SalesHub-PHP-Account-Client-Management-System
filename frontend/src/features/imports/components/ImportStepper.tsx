import { CheckIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import { IMPORT_STEPS, type ImportStepId } from '../steps'

/** Numbered progress list. The current step carries `aria-current="step"`. */
export function ImportStepper({ current }: { current: ImportStepId }) {
  const currentIndex = IMPORT_STEPS.findIndex((step) => step.id === current)

  return (
    <nav aria-label="Import steps">
      <ol className="flex flex-wrap gap-x-6 gap-y-2">
        {IMPORT_STEPS.map((step, index) => {
          const done = index < currentIndex
          const active = index === currentIndex
          return (
            <li
              key={step.id}
              aria-current={active ? 'step' : undefined}
              className={cn(
                'flex items-center gap-2 text-sm',
                active ? 'text-foreground font-medium' : 'text-muted-foreground',
              )}
            >
              <span
                aria-hidden="true"
                className={cn(
                  'flex size-6 items-center justify-center rounded-full border text-xs',
                  active && 'border-primary bg-primary text-primary-foreground',
                  done && 'border-primary text-primary',
                )}
              >
                {done ? <CheckIcon className="size-3.5" /> : index + 1}
              </span>
              <span>
                {step.label}
                {done ? <span className="sr-only"> (completed)</span> : null}
              </span>
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
