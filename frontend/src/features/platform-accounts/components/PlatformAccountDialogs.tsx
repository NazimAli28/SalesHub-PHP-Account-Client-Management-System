import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import {
  AsyncComboboxField,
  FormDialog,
  SelectField,
  TextareaField,
  TextField,
} from '@/components/form'
import { useAuth } from '@/features/auth/AuthProvider'
import { accountStandings } from '@/lib/enums'
import {
  fetchWorkstationOptions,
  useAssignWorkstation,
  useChangeStanding,
  useRequestNewAccounts,
} from '../api'
import {
  assignFormSchema,
  requestAccountsFormSchema,
  standingFormSchema,
  type AssignFormValues,
  type RequestAccountsFormValues,
  type StandingFormValues,
} from '../schemas'
import type { PlatformAccount } from '../types'

interface DialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

function workstationOption(account: PlatformAccount) {
  const workstation = account.workstation
  return workstation
    ? { value: workstation.id, label: workstation.label ?? workstation.code }
    : null
}

/** `platform-accounts.assign`: pick a workstation, or clear the field to unassign. */
export function AssignWorkstationDialog({
  account,
  open,
  onOpenChange,
}: DialogProps & { account: PlatformAccount }) {
  const form = useForm<AssignFormValues>({
    resolver: zodResolver(assignFormSchema),
    defaultValues: { workstation_id: account.workstation_id },
  })
  useEffect(() => {
    if (open) form.reset({ workstation_id: account.workstation_id })
  }, [open, account, form])

  const assign = useAssignWorkstation<AssignFormValues>({
    form,
    onSuccess: () => onOpenChange(false),
  })

  return (
    <FormDialog
      open={open}
      onOpenChange={onOpenChange}
      title="Assign workstation"
      description={`Choose where ${account.email} is used. Clear the field to unassign it.`}
      form={form}
      onSubmit={(values) =>
        assign.mutate({ id: account.id, workstation_id: values.workstation_id })
      }
      pending={assign.isPending}
      submitLabel="Save"
    >
      <AsyncComboboxField
        control={form.control}
        name="workstation_id"
        label="Workstation"
        queryKey={['workstations', 'options']}
        fetchOptions={fetchWorkstationOptions}
        initialOption={workstationOption(account)}
        placeholder="Unassigned"
        searchPlaceholder="Workstation code or label"
      />
    </FormDialog>
  )
}

/** Support/admin change the standing directly; team leads and sales executives send it for approval. */
export function ChangeStandingDialog({
  account,
  open,
  onOpenChange,
}: DialogProps & { account: PlatformAccount }) {
  const { can } = useAuth()
  const needsApproval = !can('platform-accounts.change-standing')
  const defaults: StandingFormValues = { standing: account.standing.value, reason: '' }

  const form = useForm<StandingFormValues>({
    resolver: zodResolver(standingFormSchema),
    defaultValues: defaults,
  })
  useEffect(() => {
    if (open) form.reset({ standing: account.standing.value, reason: '' })
  }, [open, account, form])

  const change = useChangeStanding<StandingFormValues>({
    form,
    onSuccess: () => onOpenChange(false),
  })

  return (
    <FormDialog
      open={open}
      onOpenChange={onOpenChange}
      title="Change standing"
      description={
        needsApproval
          ? `Your request for ${account.email} will be reviewed before the standing changes.`
          : `Update the standing of ${account.email}.`
      }
      form={form}
      onSubmit={(values) =>
        change.mutate({
          id: account.id,
          standing: values.standing,
          reason: values.reason.trim() || undefined,
        })
      }
      pending={change.isPending}
      submitLabel={needsApproval ? 'Send for approval' : 'Save'}
    >
      <SelectField
        control={form.control}
        name="standing"
        label="Standing"
        required
        options={accountStandings.options}
      />
      <TextareaField
        control={form.control}
        name="reason"
        label="Reason"
        description={needsApproval ? 'Helps the reviewer decide quickly.' : undefined}
        rows={2}
        maxLength={500}
      />
    </FormDialog>
  )
}

/** `platform-accounts.request-new`: answered with 202, support provisions after approval. */
export function RequestAccountsDialog({ open, onOpenChange }: DialogProps) {
  const { user } = useAuth()
  const ownWorkstation = user?.workstation ?? null
  const needsWorkstation = user?.workstation_id == null

  const defaults: RequestAccountsFormValues = {
    workstation_id: null,
    quantity: '1',
    note: '',
  }
  const form = useForm<RequestAccountsFormValues>({
    resolver: zodResolver(requestAccountsFormSchema),
    defaultValues: defaults,
  })
  useEffect(() => {
    if (open) form.reset(defaults)
    // eslint-disable-next-line react-hooks/exhaustive-deps -- defaults is a constant shape
  }, [open, form])

  const request = useRequestNewAccounts<RequestAccountsFormValues>({
    form,
    onSuccess: () => onOpenChange(false),
  })

  return (
    <FormDialog
      open={open}
      onOpenChange={onOpenChange}
      title="Request new accounts"
      description="Ask support for fresh platform accounts. A reviewer approves the request first."
      form={form}
      onSubmit={(values) => {
        if (needsWorkstation && values.workstation_id === null) {
          form.setError('workstation_id', { type: 'required', message: 'Choose a workstation.' })
          return
        }
        request.mutate({
          ...(values.workstation_id !== null ? { workstation_id: values.workstation_id } : {}),
          quantity: Number(values.quantity),
          ...(values.note.trim() ? { note: values.note.trim() } : {}),
        })
      }}
      pending={request.isPending}
      submitLabel="Send request"
    >
      <AsyncComboboxField
        control={form.control}
        name="workstation_id"
        label="Workstation"
        required={needsWorkstation}
        description={
          needsWorkstation
            ? undefined
            : `Leave empty to use your own workstation${ownWorkstation ? ` (${ownWorkstation.code})` : ''}.`
        }
        queryKey={['workstations', 'options']}
        fetchOptions={fetchWorkstationOptions}
        placeholder={needsWorkstation ? 'Choose a workstation' : 'My workstation'}
        searchPlaceholder="Workstation code or label"
      />
      <TextField
        control={form.control}
        name="quantity"
        label="How many accounts"
        required
        inputMode="numeric"
        maxLength={2}
        description="Between 1 and 10."
      />
      <TextareaField
        control={form.control}
        name="note"
        label="Note for support"
        rows={2}
        maxLength={500}
      />
    </FormDialog>
  )
}
