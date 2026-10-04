import { useEffect, useMemo } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { AsyncComboboxField, FormDialog, SelectField, TextField } from '@/components/form'
import { pickChanged } from '@/features/users/schemas'
import { makeTeamLeadFetcher, SHIFT_OPTIONS, useCreateTeam, useUpdateTeam, type Team } from '../api'
import { teamFormDefaults, teamFormSchema, toTeamPayload, type TeamFormValues } from '../schemas'

interface TeamFormDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Edit this team; omit to create a new one. */
  team?: Team | null
}

export function TeamFormDialog({ open, onOpenChange, team }: TeamFormDialogProps) {
  const isEdit = Boolean(team)
  const form = useForm<TeamFormValues>({
    resolver: zodResolver(teamFormSchema),
    defaultValues: teamFormDefaults(team),
  })

  useEffect(() => {
    if (open) form.reset(teamFormDefaults(team))
  }, [open, team, form])

  const close = () => onOpenChange(false)
  const create = useCreateTeam({ form, onSuccess: close })
  const update = useUpdateTeam({ form, onSuccess: close })
  const fetchLeads = useMemo(() => makeTeamLeadFetcher(team?.id ?? null), [team?.id])

  const onSubmit = (values: TeamFormValues) => {
    const payload = toTeamPayload(values)
    if (team)
      update.mutate({ id: team.id, payload: pickChanged(payload, form.formState.dirtyFields) })
    else create.mutate(payload)
  }

  return (
    <FormDialog
      open={open}
      onOpenChange={onOpenChange}
      title={isEdit ? 'Edit team' : 'New team'}
      description={isEdit ? 'Update the team details.' : 'Create a team and choose its shift.'}
      form={form}
      onSubmit={onSubmit}
      pending={create.isPending || update.isPending}
      submitLabel={isEdit ? 'Save changes' : 'Create team'}
    >
      <TextField control={form.control} name="name" label="Name" required maxLength={80} />
      <div className="grid gap-5 sm:grid-cols-2">
        <TextField
          control={form.control}
          name="floor"
          label="Floor"
          required
          inputMode="numeric"
          maxLength={3}
        />
        <SelectField
          control={form.control}
          name="shift"
          label="Shift"
          required
          options={SHIFT_OPTIONS}
        />
      </div>
      <AsyncComboboxField
        control={form.control}
        name="team_lead_id"
        label="Team lead"
        description={
          isEdit
            ? 'Active team leads who belong to this team.'
            : 'Active team leads who are not on a team yet.'
        }
        queryKey={['users', 'options', 'team-leads', team?.id ?? 'new']}
        fetchOptions={fetchLeads}
        initialOption={
          team?.team_lead ? { value: team.team_lead.id, label: team.team_lead.name } : null
        }
        placeholder="No team lead"
        searchPlaceholder="Search team leads…"
      />
    </FormDialog>
  )
}
