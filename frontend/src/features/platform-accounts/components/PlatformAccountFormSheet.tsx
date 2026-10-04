import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import {
  AsyncComboboxField,
  DateField,
  FormSheet,
  PasswordField,
  SelectField,
  TextareaField,
  TextField,
} from '@/components/form'
import { useAuth } from '@/features/auth/AuthProvider'
import { accountStandings } from '@/lib/enums'
import { fetchWorkstationOptions, useCreatePlatformAccount, useUpdatePlatformAccount } from '../api'
import {
  pickChanged,
  platformAccountFormDefaults,
  platformAccountFormSchema,
  toPlatformAccountPayload,
  type PlatformAccountFormValues,
} from '../schemas'
import type { PlatformAccount } from '../types'

interface PlatformAccountFormSheetProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Edit this account; omit to create a new one. */
  account?: PlatformAccount | null
}

function storedHint(isEdit: boolean, stored: boolean | undefined): string {
  if (!isEdit) return 'Stored encrypted. View it later with the reveal action.'
  return `${stored ? 'A value is stored.' : 'Nothing is stored.'} Leave blank to keep it unchanged. Stored values are never shown.`
}

/**
 * Create / edit a platform account. Credentials are write-only: the inputs always start empty
 * (never prefilled), blank on edit keeps the stored value, and the form says whether one is set.
 * Workstation and standing are set on create only; later changes use their own actions.
 */
export function PlatformAccountFormSheet({
  open,
  onOpenChange,
  account,
}: PlatformAccountFormSheetProps) {
  const isEdit = Boolean(account)
  const [today] = useState(() => new Date())
  const { can } = useAuth()
  // Without `platform-accounts.update`, edits are queued for approval and cannot carry credentials.
  const needsApproval = isEdit && !can('platform-accounts.update')
  const canSetCredentials = !isEdit || can('platform-accounts.update')

  const form = useForm<PlatformAccountFormValues>({
    resolver: zodResolver(platformAccountFormSchema),
    defaultValues: platformAccountFormDefaults(account),
  })

  useEffect(() => {
    if (open) form.reset(platformAccountFormDefaults(account))
  }, [open, account, form])

  const close = () => onOpenChange(false)
  const create = useCreatePlatformAccount({ form, onSuccess: close })
  const update = useUpdatePlatformAccount({ form, onSuccess: close })

  const onSubmit = (values: PlatformAccountFormValues) => {
    if (!account) {
      let valid = true
      for (const [field, message] of [
        ['email_password', 'Enter the email password.'],
        ['discord_password', 'Enter the Discord password.'],
      ] as const) {
        if (!values[field]) {
          form.setError(field, { type: 'required', message })
          valid = false
        }
      }
      if (!values.batch_date) {
        form.setError('batch_date', { type: 'required', message: 'Choose the batch date.' })
        valid = false
      }
      if (!valid) return
    }
    const payload = toPlatformAccountPayload(values, {
      create: !account,
      includeCredentials: canSetCredentials,
    })
    if (account) {
      update.mutate({ id: account.id, payload: pickChanged(payload, form.formState.dirtyFields) })
    } else {
      create.mutate(payload)
    }
  }

  return (
    <FormSheet
      open={open}
      onOpenChange={onOpenChange}
      title={isEdit ? 'Edit platform account' : 'New platform account'}
      description={
        needsApproval
          ? 'Your changes will be sent to a reviewer before they apply.'
          : isEdit
            ? 'Update the account details and credentials.'
            : 'Add a shared account to the inventory.'
      }
      form={form}
      onSubmit={onSubmit}
      pending={create.isPending || update.isPending}
      submitLabel={needsApproval ? 'Send for approval' : isEdit ? 'Save changes' : 'Create account'}
    >
      <TextField
        control={form.control}
        name="email"
        label="Account email"
        type="email"
        required
        autoComplete="off"
        maxLength={255}
      />
      <div className="grid gap-5 sm:grid-cols-2">
        <TextField
          control={form.control}
          name="discord_email"
          label="Discord email"
          type="email"
          autoComplete="off"
          maxLength={255}
        />
        <TextField
          control={form.control}
          name="discord_username"
          label="Discord username"
          autoComplete="off"
          maxLength={64}
        />
      </div>
      <div className="grid gap-5 sm:grid-cols-2">
        <DateField
          control={form.control}
          name="batch_date"
          label="Batch date"
          required
          clearable={false}
        />
        <DateField
          control={form.control}
          name="discord_created_on"
          label="Discord created on"
          maxDate={today}
        />
      </div>
      <TextField
        control={form.control}
        name="recovery_email"
        label="Recovery email"
        type="email"
        autoComplete="off"
        maxLength={255}
      />
      {!isEdit ? (
        <div className="grid gap-5 sm:grid-cols-2">
          <AsyncComboboxField
            control={form.control}
            name="workstation_id"
            label="Workstation"
            queryKey={['workstations', 'options']}
            fetchOptions={fetchWorkstationOptions}
            placeholder="Unassigned"
            searchPlaceholder="Workstation code or label"
          />
          <SelectField
            control={form.control}
            name="standing"
            label="Standing"
            options={accountStandings.options}
          />
        </div>
      ) : null}

      {canSetCredentials ? (
        <fieldset className="space-y-5 rounded-lg border p-4">
          <legend className="px-1 text-sm font-medium">Credentials (write-only)</legend>
          <PasswordField
            control={form.control}
            name="email_password"
            label="Email password"
            required={!isEdit}
            autoComplete="new-password"
            placeholder={isEdit ? (account?.has_email_password ? 'Set' : 'Not set') : undefined}
            description={storedHint(isEdit, account?.has_email_password)}
          />
          <PasswordField
            control={form.control}
            name="discord_password"
            label="Discord password"
            required={!isEdit}
            autoComplete="new-password"
            placeholder={isEdit ? (account?.has_discord_password ? 'Set' : 'Not set') : undefined}
            description={storedHint(isEdit, account?.has_discord_password)}
          />
          <div className="grid gap-5 sm:grid-cols-2">
            <PasswordField
              control={form.control}
              name="recovery_phone"
              label="Recovery phone"
              autoComplete="off"
              placeholder={isEdit ? (account?.has_recovery_phone ? 'Set' : 'Not set') : undefined}
              description={storedHint(isEdit, account?.has_recovery_phone)}
            />
            <PasswordField
              control={form.control}
              name="phone_holder_name"
              label="Phone holder name"
              autoComplete="off"
              placeholder={
                isEdit ? (account?.has_phone_holder_name ? 'Set' : 'Not set') : undefined
              }
              description={storedHint(isEdit, account?.has_phone_holder_name)}
            />
          </div>
        </fieldset>
      ) : null}

      <TextareaField control={form.control} name="notes" label="Notes" rows={3} maxLength={5000} />
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
