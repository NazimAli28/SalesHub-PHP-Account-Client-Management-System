import { cn } from '@/lib/utils'

/** The SalesHub logo mark: an upward trend inside a brand-coloured tile. */
export function BrandMark({ className }: { className?: string }) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'from-primary text-primary-foreground flex aspect-square size-8 shrink-0 items-center justify-center rounded-lg bg-linear-to-br to-violet-500 shadow-sm',
        className,
      )}
    >
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2.4"
        className="size-4.5"
      >
        <path d="M4 16.5 9.5 11l3.5 3.5L20 7.5" strokeLinecap="round" strokeLinejoin="round" />
        <path d="M14.5 7.5H20V13" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
    </span>
  )
}
