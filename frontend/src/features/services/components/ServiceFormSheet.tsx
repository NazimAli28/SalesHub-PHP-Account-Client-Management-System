import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import {
  CheckboxField,
  FormSheet,
  MoneyField,
  SelectField,
  TextareaField,
  TextField,
} from '@/components/form'
import { pickChanged } from '@/features/users/schemas'
import { SERVICE_CATEGORY_OPTIONS, useCreateService, useUpdateService, type Service } from '../api'
import {
  serviceFormDefaults,
  serviceFormSchema,
  toServicePayload,
  type ServiceFormValues,
} from '../schemas'

interface ServiceFormSheetProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Edit this service; omit to create a new one. */
  service?: Service | null
}

export function ServiceFormSheet({ open, onOpenChange, service }: ServiceFormSheetProps) {
  const isEdit = Boolean(service)
  const form = useForm<ServiceFormValues>({
    resolver: zodResolver(serviceFormSchema),
    defaultValues: serviceFormDefaults(service),
  })

  useEffect(() => {
    if (open) form.reset(serviceFormDefaults(service))
  }, [open, service, form])

  const close = () => onOpenChange(false)
  const create = useCreateService({ form, onSuccess: close })
  const update = useUpdateService({ form, onSuccess: close })

  const onSubmit = (values: ServiceFormValues) => {
    const payload = toServicePayload(values)
    if (service) {
      update.mutate({ id: service.id, payload: pickChanged(payload, form.formState.dirtyFields) })
    } else create.mutate(payload)
  }

  return (
    <FormSheet
      open={open}
      onOpenChange={onOpenChange}
      title={isEdit ? 'Edit service' : 'New service'}
      description={isEdit ? 'Update the catalog entry.' : 'Add a service to the catalog.'}
      form={form}
      onSubmit={onSubmit}
      pending={create.isPending || update.isPending}
      submitLabel={isEdit ? 'Save changes' : 'Create service'}
    >
      <TextField control={form.control} name="name" label="Name" required maxLength={80} />
      <TextField
        control={form.control}
        name="slug"
        label="Slug"
        description={
          isEdit
            ? 'Lowercase letters, numbers and dashes.'
            : 'Optional. Defaults to the name, e.g. "twitch-emote-pack".'
        }
        maxLength={80}
        spellCheck={false}
        autoComplete="off"
      />
      <div className="grid gap-5 sm:grid-cols-2">
        <SelectField
          control={form.control}
          name="category"
          label="Category"
          required
          options={SERVICE_CATEGORY_OPTIONS}
        />
        <MoneyField control={form.control} name="base_price_cents" label="Base price" required />
      </div>
      <TextareaField
        control={form.control}
        name="description"
        label="Description"
        maxLength={2000}
      />
      <CheckboxField
        control={form.control}
        name="is_active"
        label="Active"
        description="Inactive services stay in the catalog but are hidden from new orders."
      />
    </FormSheet>
  )
}
