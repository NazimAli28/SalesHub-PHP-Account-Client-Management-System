import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import {
  AsyncComboboxField,
  CheckboxField,
  DateField,
  FormSheet,
  PasswordField,
  SelectField,
  TextareaField,
  TextField,
} from '@/components/form'
import { useAuth } from '@/features/auth/AuthProvider'
import { fetchPlatformAccountOptions, useCreateSocialAccount, useUpdateSocialAccount } from '../api'
import { SOCIAL_PLATFORM_OPTIONS } from '../platforms'
import {
  pickChanged,
  socialAccountFormDefaults,
  socialAccountFormSchema,
  toSocialAccountPayload,
  type SocialAccountFormValues,
} from '../schemas'
import type { SocialAccount } from '../types'

interface SocialAccountFormSheetProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Edit this account; omit to create a new one. */
  account?: SocialAccount | null
  /** Pre-select (and lock in) a platform account when creating from its detail page. */
  defaultPlatformAccount?: { id: number; label: string } | null
}

/**
 * Create / edit a social account. The password input is write-only: it starts empty, the stored
 * value is never shown, and leaving it blank on edit keeps the current password.
 */
export function SocialAccountFormSheet({
  open,
  onOpenChange,
  account,
  defaultPlatformAccount,
}: SocialAccountFormSheetProps) {
  const isEdit = Boolean(account)
  const [today] = useState(() => new Date())
  const { can } = useAuth()
  // Without `social-accounts.update`, edits are queued for approval and cannot carry a password.
  const needsApproval = isEdit && !can('social-accounts.update')
  const canSetPassword = !isEdit || can('social-accounts.update')

  const defaults = () => {
    const values = socialAccountFormDefaults(account)
    if (!account && defaultPlatformAccount) values.platform_account_id = defaultPlatformAccount.id
    return values
  }

  const form = useForm<SocialAccountFormValues>({
    resolver: zodResolver(socialAccountFormSchema),
    defaultValues: defaults(),
  })

  useEffect(() => {
    if (open) form.reset(defaults())
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when the sheet (re)opens
  }, [open, account, defaultPlatformAccount, form])

  const close = () => onOpenChange(false)
  const create = useCreateSocialAccount({ form, onSuccess: close })
  const update = useUpdateSocialAccount({ form, onSuccess: close })

  const onSubmit = (values: SocialAccountFormValues) => {
    if (!account && !values.password) {
      form.setError('password', { type: 'required', message: 'Enter the password.' })
      return
    }
    const payload = toSocialAccountPayload(values, { includePassword: canSetPassword })
    if (account) {
      update.mutate({ id: account.id, payload: pickChanged(payload, form.formState.dirtyFields) })
    } else {
      create.mutate(payload)
    }
  }

  const accountLabel = account?.platform_account
    ? { value: account.platform_account.id, label: account.platform_account.email }
    : defaultPlatformAccount
      ? { value: defaultPlatformAccount.id, label: defaultPlatformAccount.label }
      : null

  return (
    <FormSheet
      open={open}
      onOpenChange={onOpenChange}
      title={isEdit ? 'Edit social account' : 'New social account'}
      description={
        needsApproval
          ? 'Your changes will be sent to a reviewer before they apply.'
          : isEdit
            ? 'Update the account details.'
            : 'Register a social account under a platform account.'
      }
      form={form}
      onSubmit={onSubmit}
      pending={create.isPending || update.isPending}
      submitLabel={needsApproval ? 'Send for approval' : isEdit ? 'Save changes' : 'Create account'}
    >
      <AsyncComboboxField
        control={form.control}
        name="platform_account_id"
        label="Platform account"
        required
        queryKey={['platform-accounts', 'options']}
        fetchOptions={fetchPlatformAccountOptions}
        initialOption={accountLabel}
        placeholder="Search platform accounts…"
        searchPlaceholder="Email or Discord username"
      />
      <div className="grid gap-5 sm:grid-cols-2">
        <SelectField
          control={form.control}
          name="platform"
          label="Platform"
          required
          options={SOCIAL_PLATFORM_OPTIONS}
        />
        <TextField
          control={form.control}
          name="username"
          label="Username"
          required
          autoComplete="off"
          maxLength={100}
        />
      </div>
      <TextField
        control={form.control}
        name="login_email"
        label="Login email"
        type="email"
        autoComplete="off"
        maxLength={255}
      />
      {canSetPassword ? (
        <PasswordField
          control={form.control}
          name="password"
          label="Password"
          required={!isEdit}
          autoComplete="new-password"
          placeholder={
            isEdit ? (account?.has_password ? 'Set. Type to replace it' : 'Not set') : undefined
          }
          description={
            isEdit
              ? `${account?.has_password ? 'A password is stored.' : 'No password is stored.'} Leave blank to keep it unchanged. Stored passwords are never shown.`
              : 'Stored encrypted. It can only be viewed later with the reveal action.'
          }
        />
      ) : null}
      <DateField control={form.control} name="created_on" label="Created on" maxDate={today} />
      <CheckboxField control={form.control} name="is_in_use" label="Currently in use" />
      {needsApproval ? (
        <TextareaField
          control={form.control}
          name="reason"
          label="Reason for the change"
          description="Helps the reviewer decide quickly."
          rows={2}
          maxLength={500}
        />
      ) : null}
    </FormSheet>
  )
}
