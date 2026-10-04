import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { useApiMutation } from '@/api/use-api-mutation'
import { FormRootError, PasswordField } from '@/components/form'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { Spinner } from '@/components/ui/spinner'
import { changePassword } from '@/features/auth/api'
import { changePasswordSchema, type ChangePasswordValues } from '@/features/auth/schemas'

const EMPTY: ChangePasswordValues = {
  current_password: '',
  password: '',
  password_confirmation: '',
}

/**
 * PUT /api/auth/password. The API signs out the user's other sessions on success.
 * `disabled` (public demo mode) locks the whole form; the API refuses the change anyway.
 */
export function ChangePasswordForm({ disabled = false }: { disabled?: boolean }) {
  const form = useForm<ChangePasswordValues>({
    resolver: zodResolver(changePasswordSchema),
    defaultValues: EMPTY,
  })

  const mutation = useApiMutation({
    mutationFn: changePassword,
    form,
    successMessage: 'Password changed. Other devices have been signed out.',
    onSuccess: () => form.reset(EMPTY),
  })

  return (
    <form
      noValidate
      onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
      className="max-w-md"
    >
      <fieldset disabled={disabled} className="min-w-0">
        <FieldGroup>
          <FormRootError form={form} />
          <PasswordField
            control={form.control}
            name="current_password"
            label="Current password"
            autoComplete="current-password"
            required
          />
          <PasswordField
            control={form.control}
            name="password"
            label="New password"
            autoComplete="new-password"
            description="At least 10 characters, with upper- and lowercase letters, a number and a symbol."
            required
          />
          <PasswordField
            control={form.control}
            name="password_confirmation"
            label="Confirm new password"
            autoComplete="new-password"
            required
          />
          <div>
            <Button type="submit" disabled={disabled || mutation.isPending}>
              {mutation.isPending ? <Spinner /> : null}
              Update password
            </Button>
          </div>
        </FieldGroup>
      </fieldset>
    </form>
  )
}
