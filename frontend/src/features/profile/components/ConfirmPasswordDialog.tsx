import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import type { QueryKey } from '@tanstack/react-query'
import { useApiMutation } from '@/api/use-api-mutation'
import { FormDialog, PasswordField } from '@/components/form'
import { confirmPasswordSchema, type ConfirmPasswordValues } from '@/features/auth/schemas'

interface ConfirmPasswordDialogProps<TData> {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description: string
  submitLabel: string
  /** The request that needs the password, e.g. `disableTwoFactor`. */
  action: (payload: ConfirmPasswordValues) => Promise<TData>
  successMessage?: string | ((data: TData) => string) | false
  invalidate?: QueryKey[]
  onConfirmed?: (data: TData) => void
}

/**
 * Asks for the current password before a sensitive change. A wrong password comes back as a
 * 422 on `password` and is shown inline.
 */
export function ConfirmPasswordDialog<TData>({
  open,
  onOpenChange,
  title,
  description,
  submitLabel,
  action,
  successMessage = false,
  invalidate,
  onConfirmed,
}: ConfirmPasswordDialogProps<TData>) {
  const form = useForm<ConfirmPasswordValues>({
    resolver: zodResolver(confirmPasswordSchema),
    defaultValues: { password: '' },
  })

  useEffect(() => {
    if (open) form.reset({ password: '' })
  }, [open, form])

  const mutation = useApiMutation<TData, ConfirmPasswordValues, ConfirmPasswordValues>({
    mutationFn: action,
    form,
    successMessage:
      typeof successMessage === 'function' ? (data) => successMessage(data) : successMessage,
    invalidate,
    onSuccess: (data) => {
      onOpenChange(false)
      onConfirmed?.(data)
    },
  })

  return (
    <FormDialog
      open={open}
      onOpenChange={onOpenChange}
      title={title}
      description={description}
      form={form}
      onSubmit={(values) => mutation.mutate(values)}
      pending={mutation.isPending}
      submitLabel={submitLabel}
    >
      <PasswordField
        control={form.control}
        name="password"
        label="Current password"
        autoComplete="current-password"
        autoFocus
        required
      />
    </FormDialog>
  )
}
