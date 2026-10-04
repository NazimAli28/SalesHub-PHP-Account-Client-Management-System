import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { ClockIcon } from 'lucide-react'
import { isApiError } from '@/api/errors'
import { applyFieldErrors } from '@/api/use-api-mutation'
import { CheckboxField, FormRootError, PasswordField, TextField } from '@/components/form'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { Spinner } from '@/components/ui/spinner'
import { useCountdown } from '@/hooks/use-countdown'
import { useLogin } from '../api'
import { loginSchema, type LoginValues } from '../schemas'
import { DemoLogins } from './DemoLogins'
import { TwoFactorChallengeForm } from './TwoFactorChallengeForm'

/**
 * Sign-in form. On success the `/auth/me` cache is filled, and <RedirectIfAuthenticated> on the
 * login route sends the user on to `?redirect=` (or the dashboard). Accounts with two-factor
 * sign-in get a second step (<TwoFactorChallengeForm>) before that happens.
 */
export function LoginForm() {
  const login = useLogin()
  const [step, setStep] = useState<'password' | 'two-factor'>('password')
  const [lockedUntil, setLockedUntil] = useState<number | null>(null)
  const secondsLeft = useCountdown(lockedUntil)
  const locked = secondsLeft > 0

  const form = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { login: '', password: '', remember: false },
  })

  const submit = (values: LoginValues) => {
    form.clearErrors('root')
    login.mutate(values, {
      onSuccess: (result) => {
        if (result.twoFactor) setStep('two-factor')
      },
      onError: (error) => {
        if (!isApiError(error)) {
          form.setError('root.server', { message: 'Something went wrong. Please try again.' })
          return
        }
        if (error.status === 429) {
          setLockedUntil(Date.now() + (error.retryAfter ?? 60) * 1000)
          return
        }
        if (error.status === 422) {
          applyFieldErrors(form, error)
          form.resetField('password', { keepError: false })
          return
        }
        // 403 account_inactive / ip_not_allowed and anything else: show the server's message.
        form.setError('root.server', { message: error.message })
      },
    })
  }

  if (step === 'two-factor') {
    return (
      <TwoFactorChallengeForm
        onRestart={(message) => {
          setStep('password')
          form.resetField('password')
          if (message) form.setError('root.server', { message })
        }}
      />
    )
  }

  const pending = login.isPending

  return (
    <div className="space-y-6">
      <form
        noValidate
        onSubmit={form.handleSubmit(submit)}
        aria-describedby={locked ? 'lockout' : undefined}
      >
        <FieldGroup>
          {locked ? (
            <div
              id="lockout"
              role="alert"
              className="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-sm text-amber-800 dark:text-amber-300"
            >
              <ClockIcon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
              <p>
                Too many sign-in attempts. Try again in{' '}
                <span className="tabular font-semibold">
                  {secondsLeft} {secondsLeft === 1 ? 'second' : 'seconds'}
                </span>
                .
              </p>
            </div>
          ) : (
            <FormRootError form={form} />
          )}

          <TextField
            control={form.control}
            name="login"
            label="Username or email"
            autoComplete="username"
            autoFocus
            spellCheck={false}
            placeholder="agent1 or agent1@example.com"
          />
          <PasswordField
            control={form.control}
            name="password"
            label="Password"
            autoComplete="current-password"
          />
          <CheckboxField
            control={form.control}
            name="remember"
            label="Keep me signed in on this device"
          />

          <Button type="submit" size="lg" className="w-full" disabled={pending || locked}>
            {pending ? <Spinner /> : null}
            {pending ? 'Signing in…' : 'Sign in'}
          </Button>
        </FieldGroup>
      </form>

      {import.meta.env.VITE_DEMO_MODE === 'true' ? (
        <DemoLogins
          disabled={pending || locked}
          onPick={(credentials) => {
            form.reset({ ...credentials, remember: false })
            void form.handleSubmit(submit)()
          }}
        />
      ) : null}
    </div>
  )
}
