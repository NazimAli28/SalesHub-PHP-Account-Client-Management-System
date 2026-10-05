import { InfoIcon } from 'lucide-react'

/** Explains why password and two-factor changes are off in the public demo. */
export function DemoModeNotice() {
  return (
    <div
      role="note"
      className="flex items-start gap-2 rounded-lg border border-sky-500/30 bg-sky-500/10 px-3 py-2.5 text-sm text-sky-900 dark:text-sky-200"
    >
      <InfoIcon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
      <p>
        This is the public demo, and everyone shares these accounts. Changing the password, turning
        on two-step verification and signing out other sessions are switched off so nobody gets
        locked out.
      </p>
    </div>
  )
}
