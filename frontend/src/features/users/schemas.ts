import { z } from 'zod'
import type { RoleName, User } from '@/api/types'

export const ROLE_VALUES = [
  'admin',
  'support',
  'team_lead',
  'sales_executive',
] as const satisfies readonly RoleName[]

/** Roles only holders of `users.manage-privileged` may assign or touch (UserPolicy::canGrant). */
export const PRIVILEGED_ROLES: readonly string[] = ['admin', 'support']

/** Mirrors Password::defaults(): min 10, mixed case, a number and a symbol. */
export const PASSWORD_HINT =
  'At least 10 characters with upper and lower case letters, a number and a symbol.'

export function passwordProblem(value: string): string | null {
  if (value.length < 10) return 'The password must be at least 10 characters.'
  if (!/[a-z]/.test(value) || !/[A-Z]/.test(value)) {
    return 'The password must contain upper and lower case letters.'
  }
  if (!/\d/.test(value)) return 'The password must contain at least one number.'
  if (!/[^A-Za-z0-9]/.test(value)) return 'The password must contain at least one symbol.'
  return null
}

/**
 * Client-side rules mirror backend/app/Http/Requests/Users (the API stays the authority: its
 * 422 messages are mapped onto these same fields). The password is optional when editing.
 */
export function createUserFormSchema(isEdit: boolean) {
  return z
    .object({
      name: z.string().trim().min(1, 'Enter a name.').max(120, 'Keep it under 120 characters.'),
      username: z
        .string()
        .trim()
        .min(3, 'The username must be at least 3 characters.')
        .max(50, 'Keep it under 50 characters.')
        .regex(/^[A-Za-z0-9._-]+$/, 'Use letters, numbers, dots, dashes and underscores only.'),
      email: z.string().trim().min(1, 'Enter an email address.').email('Enter a valid email.'),
      password: z.string(),
      role: z.enum(ROLE_VALUES, { error: 'Choose a role.' }),
      team_id: z.number().int().positive().nullable(),
      workstation_id: z.number().int().positive().nullable(),
    })
    .superRefine((values, context) => {
      if (!isEdit || values.password !== '') {
        const problem =
          values.password === '' ? 'Enter a password.' : passwordProblem(values.password)
        if (problem) context.addIssue({ code: 'custom', path: ['password'], message: problem })
      }
      if (values.workstation_id !== null && values.team_id === null) {
        context.addIssue({
          code: 'custom',
          path: ['workstation_id'],
          message: 'Choose a team before choosing a workstation.',
        })
      }
    })
}

export type UserFormValues = z.infer<ReturnType<typeof createUserFormSchema>>

export interface UserPayload {
  name: string
  username: string
  email: string
  password?: string
  role: RoleName
  team_id: number | null
  workstation_id: number | null
}

export function userFormDefaults(user?: User | null): UserFormValues {
  const role = user?.roles[0]
  return {
    name: user?.name ?? '',
    username: user?.username ?? '',
    email: user?.email ?? '',
    password: '',
    role: (ROLE_VALUES as readonly string[]).includes(role ?? '')
      ? (role as RoleName)
      : 'sales_executive',
    team_id: user?.team_id ?? null,
    workstation_id: user?.workstation_id ?? null,
  }
}

export function toUserPayload(values: UserFormValues): UserPayload {
  return {
    name: values.name.trim(),
    username: values.username.trim(),
    email: values.email.trim(),
    password: values.password === '' ? undefined : values.password,
    role: values.role,
    team_id: values.team_id,
    workstation_id: values.workstation_id,
  }
}

/** For edits: only the fields the user changed (PATCH is partial). */
export function pickChanged<T extends object>(
  payload: T,
  dirty: Partial<Record<keyof T, unknown>>,
): Partial<T> {
  return Object.fromEntries(
    Object.entries(payload).filter(([key, value]) => key in dirty && value !== undefined),
  ) as Partial<T>
}
