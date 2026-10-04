import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm, useWatch } from 'react-hook-form'
import {
  AsyncComboboxField,
  DateField,
  FormSheet,
  SelectField,
  TextareaField,
  TextField,
} from '@/components/form'
import { useAuth } from '@/features/auth/AuthProvider'
import { clientStatuses } from '@/lib/enums'
import { fetchUserOptions, useCreateClient, useUpdateClient } from '../api'
import {
  clientFormDefaults,
  clientFormSchema,
  pickChanged,
  toClientPayload,
  type ClientFormValues,
} from '../schemas'
import type { ClientRecord } from '../types'

interface ClientFormSheetProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Edit this client; omit to create a new one. */
  client?: ClientRecord | null
}

/** Create / edit form for a client. */
export function ClientFormSheet({ open, onOpenChange, client }: ClientFormSheetProps) {
  const isEdit = Boolean(client)
  const { can } = useAuth()
  // Without `clients.update`, edits go to the approval queue: ask why.
  const needsApproval = isEdit && !can('clients.update')

  const form = useForm<ClientFormValues>({
    resolver: zodResolver(clientFormSchema),
    defaultValues: clientFormDefaults(client),
  })
  const status = useWatch({ control: form.control, name: 'status' })

  useEffect(() => {
    if (open) form.reset(clientFormDefaults(client))
  }, [open, client, form])

  const close = () => onOpenChange(false)
  const create = useCreateClient({ form, onSuccess: close })
  const update = useUpdateClient({ form, onSuccess: close })

  const onSubmit = (values: ClientFormValues) => {
    const payload = toClientPayload(values)
    if (client) {
      update.mutate({ id: client.id, payload: pickChanged(payload, form.formState.dirtyFields) })
    } else {
      // Unset optional fields are left out so the API applies its defaults.
      create.mutate(payload)
    }
  }

  return (
    <FormSheet
      open={open}
      onOpenChange={onOpenChange}
      title={isEdit ? 'Edit client' : 'New client'}
      description={
        needsApproval
          ? 'Your changes will be sent to a reviewer before they apply.'
          : isEdit
            ? 'Update the client profile.'
            : 'Add a client you are working with.'
      }
      form={form}
      onSubmit={onSubmit}
      pending={create.isPending || update.isPending}
      submitLabel={needsApproval ? 'Send for approval' : isEdit ? 'Save changes' : 'Create client'}
    >
      <TextField
        control={form.control}
        name="discord_username"
        label="Discord username"
        required
        autoComplete="off"
        maxLength={64}
      />
      <div className="grid gap-5 sm:grid-cols-2">
        <TextField control={form.control} name="name" label="Name" maxLength={120} />
        <TextField
          control={form.control}
          name="email"
          label="Email"
          type="email"
          autoComplete="off"
        />
      </div>
      <div className="grid gap-5 sm:grid-cols-2">
        <TextField
          control={form.control}
          name="payment_name"
          label="Name on payments"
          maxLength={120}
        />
        <TextField
          control={form.control}
          name="country"
          label="Country"
          description="2-letter code, e.g. US"
          maxLength={2}
        />
      </div>
      <div className="grid gap-5 sm:grid-cols-2">
        <SelectField
          control={form.control}
          name="status"
          label="Status"
          required
          options={clientStatuses.options}
        />
        <TextField
          control={form.control}
          name="nurturing_rating"
          label="Nurturing rating"
          description="0 to 100"
          inputMode="numeric"
        />
      </div>
      {status === 'lost' ? (
        <TextareaField
          control={form.control}
          name="lost_note"
          label="Why was the client lost?"
          rows={2}
          maxLength={2000}
        />
      ) : null}
      {can('users.view') ? (
        <AsyncComboboxField
          control={form.control}
          name="owner_id"
          label="Owner"
          queryKey={['users', 'options', 'search']}
          fetchOptions={fetchUserOptions}
          initialOption={
            client?.owner ? { value: client.owner.id, label: client.owner.name } : null
          }
          placeholder="Search users…"
          searchPlaceholder="Name, username or email"
        />
      ) : null}
      <div className="grid gap-5 sm:grid-cols-2">
        <DateField control={form.control} name="expected_upsell_on" label="Expected upsell" />
      </div>
      <TextareaField
        control={form.control}
        name="next_upsell_plan"
        label="Next upsell plan"
        rows={2}
        maxLength={5000}
      />
      <TextareaField control={form.control} name="notes" label="Notes" maxLength={5000} />
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
