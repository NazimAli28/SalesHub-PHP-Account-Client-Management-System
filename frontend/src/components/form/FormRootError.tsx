import { CircleAlertIcon } from 'lucide-react'
import type { FieldValues, UseFormReturn } from 'react-hook-form'

/**
 * Form-level error (e.g. a 422 for a field the form doesn't show, or a 429 on login).
 * `useApiMutation` / `applyFieldErrors` put these in `errors.root.server`.
 */
export function FormRootError<TFieldValues extends FieldValues>({
  form,
}: {
  form: UseFormReturn<TFieldValues>
}) {
  const message = form.formState.errors.root?.server?.message
  if (!message) return null
  return (
    <div
      role="alert"
      className="border-destructive/30 bg-destructive/5 text-destructive flex items-start gap-2 rounded-lg border px-3 py-2.5 text-sm"
    >
      <CircleAlertIcon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
      <p>{message}</p>
    </div>
  )
}
