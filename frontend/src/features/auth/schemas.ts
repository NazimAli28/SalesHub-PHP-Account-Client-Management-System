import { z } from 'zod'

export const loginSchema = z.object({
  login: z.string().trim().min(1, 'Enter your username or email.').max(255),
  password: z.string().min(1, 'Enter your password.'),
  remember: z.boolean(),
})

export type LoginValues = z.infer<typeof loginSchema>

/** Mirrors the backend's Password::defaults(): 10+ chars, mixed case, a number and a symbol. */
export const newPasswordSchema = z
  .string()
  .min(10, 'Use at least 10 characters.')
  .regex(/[a-z]/, 'Add a lowercase letter.')
  .regex(/[A-Z]/, 'Add an uppercase letter.')
  .regex(/\d/, 'Add a number.')
  .regex(/[^A-Za-z0-9]/, 'Add a symbol.')

export const changePasswordSchema = z
  .object({
    current_password: z.string().min(1, 'Enter your current password.'),
    password: newPasswordSchema,
    password_confirmation: z.string().min(1, 'Confirm your new password.'),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ['password_confirmation'],
    message: 'The passwords do not match.',
  })
  .refine((values) => values.password !== values.current_password, {
    path: ['password'],
    message: 'Choose a password different from your current one.',
  })

export type ChangePasswordValues = z.infer<typeof changePasswordSchema>
