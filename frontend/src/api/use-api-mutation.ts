import {
  useMutation,
  useQueryClient,
  type QueryKey,
  type UseMutationOptions,
  type UseMutationResult,
} from '@tanstack/react-query'
import type { FieldValues, Path, UseFormReturn } from 'react-hook-form'
import { toast } from 'sonner'
import { isQueued } from './client'
import { ApiError, errorMessage, isApiError } from './errors'

export interface UseApiMutationOptions<
  TData,
  TVariables,
  TFieldValues extends FieldValues = FieldValues,
> extends Omit<UseMutationOptions<TData, ApiError, TVariables>, 'mutationFn'> {
  mutationFn: (variables: TVariables) => Promise<TData>
  /** Toast shown when the change is applied. `false` disables it. */
  successMessage?: string | ((data: TData, variables: TVariables) => string) | false
  /** Toast shown on 202 (queued for approval). `false` disables it. */
  queuedMessage?: string | false
  /**
   * When set, 422 field errors are shown inline on this form instead of in a toast.
   * Errors for fields the form does not have go to `errors.root.server`.
   */
  form?: UseFormReturn<TFieldValues>
  /** Maps API field names to form field names when they differ, e.g. `{ estimated_value_cents: 'estimatedValue' }`. */
  fieldMap?: Partial<Record<string, Path<TFieldValues>>>
  /** Query keys to invalidate after a successful (applied or queued) write. */
  invalidate?: QueryKey[]
}

export const QUEUED_TITLE = 'Sent for approval'
const QUEUED_DESCRIPTION = 'An approver will review your change. You can follow it under Approvals.'

/**
 * `useMutation` with the app's conventions built in:
 *
 * - success toast (or "Sent for approval" on 202, see `api.send`)
 * - 422 errors mapped onto react-hook-form fields (pass `form`)
 * - error toast for everything else (401 is skipped: the global handler redirects to login)
 * - query invalidation after success
 *
 * Your own `onSuccess` / `onError` still run after these defaults.
 */
export function useApiMutation<
  TData = unknown,
  TVariables = void,
  TFieldValues extends FieldValues = FieldValues,
>(
  options: UseApiMutationOptions<TData, TVariables, TFieldValues>,
): UseMutationResult<TData, ApiError, TVariables> {
  const queryClient = useQueryClient()
  const {
    successMessage,
    queuedMessage = QUEUED_TITLE,
    form,
    fieldMap,
    invalidate,
    onSuccess,
    onError,
    ...rest
  } = options

  return useMutation<TData, ApiError, TVariables>({
    ...rest,
    onSuccess: async (data, variables, onMutateResult, context) => {
      if (isQueued(data)) {
        if (queuedMessage) toast.success(queuedMessage, { description: QUEUED_DESCRIPTION })
      } else if (successMessage) {
        toast.success(
          typeof successMessage === 'function' ? successMessage(data, variables) : successMessage,
        )
      }
      await Promise.all(
        (invalidate ?? []).map((queryKey) => queryClient.invalidateQueries({ queryKey })),
      )
      await onSuccess?.(data, variables, onMutateResult, context)
    },
    onError: async (error, variables, onMutateResult, context) => {
      const apiError = isApiError(error)
        ? error
        : new ApiError({ status: 500, message: errorMessage(error) })
      if (form && apiError.isValidation) {
        applyFieldErrors(form, apiError, fieldMap)
      } else if (!apiError.isUnauthenticated) {
        toast.error(apiError.message)
      }
      await onError?.(apiError, variables, onMutateResult, context)
    },
  })
}

/** Copies 422 errors onto the form. Exported for screens that submit without `useApiMutation`. */
export function applyFieldErrors<TFieldValues extends FieldValues>(
  form: UseFormReturn<TFieldValues>,
  error: ApiError,
  fieldMap?: Partial<Record<string, Path<TFieldValues>>>,
): void {
  const values = form.getValues() as Record<string, unknown>
  const unmatched: string[] = []
  let focused = false

  for (const [apiField, messages] of Object.entries(error.errors)) {
    const message = messages[0]
    if (!message) continue
    const field = fieldMap?.[apiField] ?? apiField
    const topLevel = field.split('.')[0] ?? field
    if (topLevel in values) {
      form.setError(
        field as Path<TFieldValues>,
        { type: 'server', message },
        { shouldFocus: !focused },
      )
      focused = true
    } else {
      unmatched.push(message)
    }
  }

  if (unmatched.length > 0 || Object.keys(error.errors).length === 0) {
    form.setError('root.server' as Path<TFieldValues>, {
      type: 'server',
      message: unmatched.length > 0 ? unmatched.join(' ') : error.message,
    })
  }
}
