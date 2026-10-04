import { z } from 'zod'
import type { Workstation } from './api'

/** Mirrors backend/app/Http/Requests/Workstations (the API's 422 messages land on these fields). */
export const workstationFormSchema = z
  .object({
    code: z.string().trim().min(1, 'Enter a code.').max(20, 'Keep it under 20 characters.'),
    label: z.string().trim().max(80, 'Keep it under 80 characters.'),
    team_id: z.number({ error: 'Choose a team.' }).int().positive('Choose a team.').nullable(),
    is_active: z.boolean(),
  })
  .superRefine((values, context) => {
    if (values.team_id === null) {
      context.addIssue({ code: 'custom', path: ['team_id'], message: 'Choose a team.' })
    }
  })

export type WorkstationFormValues = z.infer<typeof workstationFormSchema>

export interface WorkstationPayload {
  code: string
  label: string | null
  team_id: number
  is_active: boolean
}

export function workstationFormDefaults(workstation?: Workstation | null): WorkstationFormValues {
  return {
    code: workstation?.code ?? '',
    label: workstation?.label ?? '',
    team_id: workstation?.team_id ?? null,
    is_active: workstation?.is_active ?? true,
  }
}

export function toWorkstationPayload(values: WorkstationFormValues): WorkstationPayload {
  return {
    code: values.code.trim(),
    label: values.label.trim() || null,
    team_id: values.team_id!,
    is_active: values.is_active,
  }
}
