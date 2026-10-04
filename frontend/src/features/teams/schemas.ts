import { z } from 'zod'
import type { Team } from './api'

const SHIFTS = ['morning', 'evening', 'night'] as const

/** Mirrors backend/app/Http/Requests/Teams (the API's 422 messages land on these fields). */
export const teamFormSchema = z.object({
  name: z.string().trim().min(1, 'Enter a team name.').max(80, 'Keep it under 80 characters.'),
  /** Kept as text while typing; converted to an integer in the payload. */
  floor: z
    .string()
    .trim()
    .min(1, 'Enter a floor number.')
    .regex(/^\d+$/, 'Enter a whole number.')
    .refine((value) => Number(value) <= 255, 'The floor cannot be above 255.'),
  shift: z.enum(SHIFTS, { error: 'Choose a shift.' }),
  team_lead_id: z.number().int().positive().nullable(),
})

export type TeamFormValues = z.infer<typeof teamFormSchema>

export interface TeamPayload {
  name: string
  floor: number
  shift: (typeof SHIFTS)[number]
  team_lead_id: number | null
}

export function teamFormDefaults(team?: Team | null): TeamFormValues {
  const shift = team?.shift?.value
  return {
    name: team?.name ?? '',
    floor: team ? String(team.floor) : '',
    shift: (SHIFTS as readonly string[]).includes(shift ?? '')
      ? (shift as TeamFormValues['shift'])
      : 'morning',
    team_lead_id: team?.team_lead_id ?? null,
  }
}

export function toTeamPayload(values: TeamFormValues): TeamPayload {
  return {
    name: values.name.trim(),
    floor: Number(values.floor),
    shift: values.shift,
    team_lead_id: values.team_lead_id,
  }
}
