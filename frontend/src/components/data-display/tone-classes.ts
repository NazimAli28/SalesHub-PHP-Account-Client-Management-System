import type { Tone } from '@/lib/enums'

/** Soft background + strong text + dot, tuned for both themes. Exported for tests and legends. */
export const TONE_CLASSES: Record<Tone, string> = {
  neutral: 'bg-slate-500/10 text-slate-700 dark:text-slate-300 [--dot:var(--color-slate-500)]',
  info: 'bg-sky-500/10 text-sky-700 dark:text-sky-300 [--dot:var(--color-sky-500)]',
  brand: 'bg-primary/10 text-primary [--dot:var(--color-primary)]',
  success:
    'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 [--dot:var(--color-emerald-500)]',
  warning: 'bg-amber-500/15 text-amber-800 dark:text-amber-300 [--dot:var(--color-amber-500)]',
  danger: 'bg-red-500/10 text-red-700 dark:text-red-300 [--dot:var(--color-red-500)]',
  muted: 'bg-muted text-muted-foreground [--dot:var(--color-muted-foreground)]',
}
