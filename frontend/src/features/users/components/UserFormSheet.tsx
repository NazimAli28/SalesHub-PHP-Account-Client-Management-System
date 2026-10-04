import { useEffect, useMemo, useRef } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm, useWatch } from 'react-hook-form'
import type { User } from '@/api/types'
import {
  AsyncComboboxField,
  FormSheet,
  PasswordField,
  SelectField,
  TextField,
} from '@/components/form'
import { useAuth } from '@/features/auth/AuthProvider'
import { roleLabel } from '@/lib/roles'
import { fetchTeamOptions } from '@/features/teams/api'
import { makeWorkstationFetcher } from '@/features/workstations/api'
import { useCreateUser, useUpdateUser } from '../api'
import { assignableRoleOptions, isSelf } from '../permissions'
import {
  createUserFormSchema,
  PASSWORD_HINT,
  pickChanged,
  toUserPayload,
  userFormDefaults,
  type UserFormValues,
} from '../schemas'

interface UserFormSheetProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Edit this user; omit to create a new one. */
  user?: User | null
}

/** Create / edit form for a user. The role list only offers roles the actor may assign. */
export function UserFormSheet({ open, onOpenChange, user }: UserFormSheetProps) {
  const isEdit = Boolean(user)
  const auth = useAuth()
  const editingSelf = Boolean(user) && isSelf(auth.user, user!)

  const roleOptions = useMemo(() => {
    const options = assignableRoleOptions(auth)
    const current = user?.roles[0]
    // Your own role is fixed: changing it could lock you out.
    if (editingSelf && current) return [{ value: current, label: roleLabel(current) }]
    // Keep the current role visible even if the actor could not assign it (the API still decides).
    return current && !options.some((option) => option.value === current)
      ? [...options, { value: current, label: roleLabel(current) }]
      : options
  }, [auth, user, editingSelf])

  const schema = useMemo(() => createUserFormSchema(isEdit), [isEdit])
  const form = useForm<UserFormValues>({
    resolver: zodResolver(schema),
    defaultValues: userFormDefaults(user),
  })
  const teamId = useWatch({ control: form.control, name: 'team_id' })

  // Fresh values every time the sheet opens (or switches to another user).
  const lastTeam = useRef<number | null>(teamId)
  useEffect(() => {
    if (open) {
      const defaults = userFormDefaults(user)
      lastTeam.current = defaults.team_id
      form.reset(defaults)
    }
  }, [open, user, form])

  // A workstation belongs to one team: changing the team clears the workstation.
  useEffect(() => {
    if (lastTeam.current !== teamId) {
      lastTeam.current = teamId
      form.setValue('workstation_id', null, { shouldDirty: true })
    }
  }, [teamId, form])

  const close = () => onOpenChange(false)
  const create = useCreateUser({ form, onSuccess: close })
  const update = useUpdateUser({ form, onSuccess: close })
  const fetchWorkstations = useMemo(() => makeWorkstationFetcher(teamId), [teamId])

  const onSubmit = (values: UserFormValues) => {
    const payload = toUserPayload(values)
    if (user) {
      update.mutate({ id: user.id, payload: pickChanged(payload, form.formState.dirtyFields) })
    } else create.mutate(payload)
  }

  return (
    <FormSheet
      open={open}
      onOpenChange={onOpenChange}
      title={isEdit ? 'Edit user' : 'New user'}
      description={
        isEdit ? 'Update the account details.' : 'Create an account and give it a single role.'
      }
      form={form}
      onSubmit={onSubmit}
      pending={create.isPending || update.isPending}
      submitLabel={isEdit ? 'Save changes' : 'Create user'}
    >
      <TextField control={form.control} name="name" label="Full name" required maxLength={120} />
      <div className="grid gap-5 sm:grid-cols-2">
        <TextField
          control={form.control}
          name="username"
          label="Username"
          required
          autoComplete="off"
          spellCheck={false}
          maxLength={50}
        />
        <TextField
          control={form.control}
          name="email"
          label="Email"
          type="email"
          required
          autoComplete="off"
          spellCheck={false}
        />
      </div>
      <SelectField
        control={form.control}
        name="role"
        label="Role"
        required
        options={roleOptions}
        description={editingSelf ? 'You cannot change your own role.' : undefined}
      />
      <div className="grid gap-5 sm:grid-cols-2">
        <AsyncComboboxField
          control={form.control}
          name="team_id"
          label="Team"
          queryKey={['teams', 'options']}
          fetchOptions={fetchTeamOptions}
          initialOption={user?.team ? { value: user.team.id, label: user.team.name } : null}
          placeholder="No team"
          searchPlaceholder="Search teams…"
        />
        <AsyncComboboxField
          key={teamId ?? 'no-team'}
          control={form.control}
          name="workstation_id"
          label="Workstation"
          description={teamId === null ? 'Choose a team first.' : undefined}
          queryKey={['workstations', 'options', teamId]}
          fetchOptions={fetchWorkstations}
          initialOption={
            user?.workstation && user.team_id === teamId
              ? { value: user.workstation.id, label: user.workstation.code }
              : null
          }
          placeholder="No workstation"
          searchPlaceholder="Search workstations…"
        />
      </div>
      <PasswordField
        control={form.control}
        name="password"
        label={isEdit ? 'New password' : 'Password'}
        required={!isEdit}
        autoComplete="new-password"
        description={
          isEdit ? `Leave blank to keep the current password. ${PASSWORD_HINT}` : PASSWORD_HINT
        }
      />
    </FormSheet>
  )
}
