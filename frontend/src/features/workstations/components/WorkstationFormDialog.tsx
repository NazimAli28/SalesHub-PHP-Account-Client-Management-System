import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { AsyncComboboxField, CheckboxField, FormDialog, TextField } from '@/components/form'
import { fetchTeamOptions } from '@/features/teams/api'
import { pickChanged } from '@/features/users/schemas'
import { useCreateWorkstation, useUpdateWorkstation, type Workstation } from '../api'
import {
  toWorkstationPayload,
  workstationFormDefaults,
  workstationFormSchema,
  type WorkstationFormValues,
} from '../schemas'

interface WorkstationFormDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Edit this workstation; omit to create a new one. */
  workstation?: Workstation | null
}

export function WorkstationFormDialog({
  open,
  onOpenChange,
  workstation,
}: WorkstationFormDialogProps) {
  const isEdit = Boolean(workstation)
  const form = useForm<WorkstationFormValues>({
    resolver: zodResolver(workstationFormSchema),
    defaultValues: workstationFormDefaults(workstation),
  })

  useEffect(() => {
    if (open) form.reset(workstationFormDefaults(workstation))
  }, [open, workstation, form])

  const close = () => onOpenChange(false)
  const create = useCreateWorkstation({ form, onSuccess: close })
  const update = useUpdateWorkstation({ form, onSuccess: close })

  const onSubmit = (values: WorkstationFormValues) => {
    const payload = toWorkstationPayload(values)
    if (workstation) {
      update.mutate({
        id: workstation.id,
        payload: pickChanged(payload, form.formState.dirtyFields),
      })
    } else create.mutate(payload)
  }

  return (
    <FormDialog
      open={open}
      onOpenChange={onOpenChange}
      title={isEdit ? 'Edit workstation' : 'New workstation'}
      description={
        isEdit ? 'Update the workstation details.' : 'Add a seat that users and accounts attach to.'
      }
      form={form}
      onSubmit={onSubmit}
      pending={create.isPending || update.isPending}
      submitLabel={isEdit ? 'Save changes' : 'Create workstation'}
    >
      <div className="grid gap-5 sm:grid-cols-2">
        <TextField control={form.control} name="code" label="Code" required maxLength={20} />
        <TextField control={form.control} name="label" label="Label" maxLength={80} />
      </div>
      <AsyncComboboxField
        control={form.control}
        name="team_id"
        label="Team"
        required
        queryKey={['teams', 'options']}
        fetchOptions={fetchTeamOptions}
        initialOption={
          workstation?.team ? { value: workstation.team.id, label: workstation.team.name } : null
        }
        placeholder="Select a team…"
        searchPlaceholder="Search teams…"
      />
      <CheckboxField control={form.control} name="is_active" label="Active" />
    </FormDialog>
  )
}
