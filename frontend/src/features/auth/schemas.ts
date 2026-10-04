import { z } from 'zod'

export const loginSchema = z.object({
  login: z.string().trim().min(1, 'Enter your username or email.').max(255),
  password: z.string().min(1, 'Enter your password.'),
  remember: z.boolean(),
})

export type LoginValues = z.infer<typeof loginSchema>

/** Second sign-in step: a 6-digit code, or a recovery code when `useRecoveryCode` is on. */
export const twoFactorChallengeSchema = z
  .object({
    useRecoveryCode: z.boolean(),
    code: z.string(),
    recovery_code: z.string(),
  })
  .superRefine((values, context) => {
    if (values.useRecoveryCode) {
      if (values.recovery_code.trim() === '') {
        context.addIssue({
          code: 'custom',
          path: ['recovery_code'],
          message: 'Enter one of your recovery codes.',
        })
      }
    } else if (!/^\d{6}$/.test(values.code)) {
      context.addIssue({
        code: 'custom',
        path: ['code'],
        message: 'Enter the 6-digit code from your authenticator app.',
      })
    }
  })

export type TwoFactorChallengeValues = z.infer<typeof twoFactorChallengeSchema>

/** A 6-digit authenticator code (two-factor setup). */
export const otpCodeSchema = z.object({
  code: z.string().regex(/^\d{6}$/, 'Enter the 6-digit code from your authenticator app.'),
})

export type OtpCodeValues = z.infer<typeof otpCodeSchema>

/** Re-entering the current password before a sensitive change. */
export const confirmPasswordSchema = z.object({
  password: z.string().min(1, 'Enter your password.'),
})

export type ConfirmPasswordValues = z.infer<typeof confirmPasswordSchema>

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
