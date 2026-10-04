import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm, useWatch } from 'react-hook-form'
import type { Lead } from '@/api/types'
import {
  AsyncComboboxField,
  DateField,
  FormSheet,
  MoneyField,
  SelectField,
  TextareaField,
} from '@/components/form'
import { useAuth } from '@/features/auth/AuthProvider'
import { leadLostReasons, leadStages } from '@/lib/enums'
import { fetchClientOptions, useCreateLead, useUpdateLead } from '../api'
import {
  leadFormDefaults,
  leadFormSchema,
  pickChanged,
  toLeadPayload,
  type LeadFormValues,
} from '../schemas'

interface LeadFormSheetProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Edit this lead; omit to create a new one. */
  lead?: Lead | null
}

/** `won` is set through orders, never by hand (the API rejects it). */
const STAGE_OPTIONS = leadStages.options.filter((option) => option.value !== 'won')

/**
 * Create / edit form for a lead. Reference for every module's form:
 * schema + defaults (schemas.ts) -> RHF -> typed fields -> mutation with 422 mapping and 202 handling.
 */
export function LeadFormSheet({ open, onOpenChange, lead }: LeadFormSheetProps) {
  const isEdit = Boolean(lead)
  const [today] = useState(() => new Date())
  const { can } = useAuth()
  // Without `leads.update`, edits go to the approval queue: ask why.
  const needsApproval = isEdit && !can('leads.update')

  const form = useForm<LeadFormValues>({
    resolver: zodResolver(leadFormSchema),
    defaultValues: leadFormDefaults(lead),
  })
  const stage = useWatch({ control: form.control, name: 'stage' })

  // Fresh values every time the sheet opens (or switches to another lead).
  useEffect(() => {
    if (open) form.reset(leadFormDefaults(lead))
  }, [open, lead, form])

  const close = () => onOpenChange(false)
  const create = useCreateLead({ form, onSuccess: close })
  const update = useUpdateLead({ form, onSuccess: close })
  const pending = create.isPending || update.isPending

  const onSubmit = (values: LeadFormValues) => {
    const payload = toLeadPayload(values)
    if (lead)
      update.mutate({ id: lead.id, payload: pickChanged(payload, form.formState.dirtyFields) })
    else create.mutate(payload)
  }

  return (
    <FormSheet
      open={open}
      onOpenChange={onOpenChange}
      title={isEdit ? 'Edit lead' : 'New lead'}
      description={
        needsApproval
          ? 'Your changes will be sent to a reviewer before they apply.'
          : isEdit
            ? 'Update the lead details.'
            : 'Log a new conversation with a client.'
      }
      form={form}
      onSubmit={onSubmit}
      pending={pending}
      submitLabel={needsApproval ? 'Send for approval' : isEdit ? 'Save changes' : 'Create lead'}
    >
      <AsyncComboboxField
        control={form.control}
        name="client_id"
        label="Client"
        required
        queryKey={['clients', 'options']}
        fetchOptions={fetchClientOptions}
        initialOption={
          lead?.client
            ? { value: lead.client.id, label: lead.client.name ?? lead.client.discord_username }
            : null
        }
        placeholder="Search clients…"
        searchPlaceholder="Name, email or Discord username"
      />
      <div className="grid gap-5 sm:grid-cols-2">
        <SelectField
          control={form.control}
          name="stage"
          label="Stage"
          required
          options={STAGE_OPTIONS}
        />
        <MoneyField control={form.control} name="estimated_value_cents" label="Estimated value" />
      </div>
      {stage === 'lost' ? (
        <SelectField
          control={form.control}
          name="lost_reason"
          label="Lost reason"
          required
          options={leadLostReasons.options}
          placeholder="Why was it lost?"
        />
      ) : null}
      <div className="grid gap-5 sm:grid-cols-2">
        <DateField
          control={form.control}
          name="contacted_on"
          label="Contacted on"
          maxDate={today}
          clearable={false}
        />
        <DateField control={form.control} name="next_follow_up_on" label="Next follow-up" />
      </div>
      <TextareaField
        control={form.control}
        name="last_message"
        label="Last message"
        placeholder="What did the client say?"
        maxLength={5000}
      />
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
