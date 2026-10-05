import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { useApiMutation } from '@/api/use-api-mutation'
import { FormDialog, PasswordField, TextField } from '@/components/form'
import { authKeys } from '@/features/auth/api'
import { disableTwoFactor } from '../api'

const disableTwoFactorSchema = z.object({
  password: z.string().min(1, 'Enter your password.'),
  code: z
    .string()
    .trim()
    .min(1, 'Enter a code from your authenticator app or a recovery code.')
    .max(32, 'That code is too long.'),
})

type DisableTwoFactorValues = z.infer<typeof disableTwoFactorSchema>

/**
 * Turning two-step verification off needs the current password and a second factor: a code from
 * the authenticator app or an unused recovery code (which is spent). Wrong values come back as
 * 422s on `password` or `code` and are shown inline.
 */
export function DisableTwoFactorDialog({
  open,
  onOpenChange,
  onDisabled,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  onDisabled?: () => void
}) {
  const form = useForm<DisableTwoFactorValues>({
    resolver: zodResolver(disableTwoFactorSchema),
    defaultValues: { password: '', code: '' },
  })

  useEffect(() => {
    if (open) form.reset({ password: '', code: '' })
  }, [open, form])

  const mutation = useApiMutation<void, DisableTwoFactorValues, DisableTwoFactorValues>({
    mutationFn: disableTwoFactor,
    form,
    successMessage: 'Two-step verification is off.',
    invalidate: [authKeys.me],
    onSuccess: () => {
      onOpenChange(false)
      onDisabled?.()
    },
  })

  return (
    <FormDialog
      open={open}
      onOpenChange={onOpenChange}
      title="Turn off two-step verification?"
      description="Signing in will only need your password again. Confirm with your password and a code."
      form={form}
      onSubmit={(values) => mutation.mutate(values)}
      pending={mutation.isPending}
      submitLabel="Turn off"
    >
      <PasswordField
        control={form.control}
        name="password"
        label="Current password"
        autoComplete="current-password"
        autoFocus
        required
      />
      <TextField
        control={form.control}
        name="code"
        label="Authentication code"
        description="The 6-digit code from your authenticator app, or one of your recovery codes."
        autoComplete="one-time-code"
        spellCheck={false}
        maxLength={32}
        required
      />
    </FormDialog>
  )
}
