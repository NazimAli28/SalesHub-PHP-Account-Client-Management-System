import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm, useWatch } from 'react-hook-form'
import { ArrowLeftIcon, ClockIcon, ShieldCheckIcon } from 'lucide-react'
import { isApiError } from '@/api/errors'
import { applyFieldErrors } from '@/api/use-api-mutation'
import { FormRootError, TextField } from '@/components/form'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { Spinner } from '@/components/ui/spinner'
import { useCountdown } from '@/hooks/use-countdown'
import { useTwoFactorChallenge } from '../api'
import { twoFactorChallengeSchema, type TwoFactorChallengeValues } from '../schemas'
import { OtpCodeField } from './OtpCodeField'

/**
 * Second sign-in step for accounts with two-factor sign-in. On success the `/auth/me` cache is
 * filled, and <RedirectIfAuthenticated> sends the user on exactly like a one-step sign-in.
 */
export function TwoFactorChallengeForm({
  onRestart,
}: {
  /** Back to the password step, with an optional message to show there. */
  onRestart: (message?: string) => void
}) {
  const challenge = useTwoFactorChallenge()
  const [lockedUntil, setLockedUntil] = useState<number | null>(null)
  const secondsLeft = useCountdown(lockedUntil)
  const locked = secondsLeft > 0

  const form = useForm<TwoFactorChallengeValues>({
    resolver: zodResolver(twoFactorChallengeSchema),
    defaultValues: { useRecoveryCode: false, code: '', recovery_code: '' },
  })
  const recoveryMode = useWatch({ control: form.control, name: 'useRecoveryCode' })

  const submit = (values: TwoFactorChallengeValues) => {
    form.clearErrors('root')
    const payload = values.useRecoveryCode
      ? { recovery_code: values.recovery_code.trim() }
      : { code: values.code }
    challenge.mutate(payload, {
      onError: (error) => {
        if (!isApiError(error)) {
          form.setError('root.server', { message: 'Something went wrong. Please try again.' })
          return
        }
        if (error.code === 'two_factor_expired') {
          onRestart(error.message)
          return
        }
        if (error.status === 429) {
          setLockedUntil(Date.now() + (error.retryAfter ?? 60) * 1000)
          return
        }
        if (error.status === 422) {
          applyFieldErrors(form, error)
          form.setValue('code', '')
          return
        }
        form.setError('root.server', { message: error.message })
      },
    })
  }

  const toggleMethod = () => {
    form.clearErrors()
    form.reset({ useRecoveryCode: !recoveryMode, code: '', recovery_code: '' })
  }

  const pending = challenge.isPending

  return (
    <form noValidate onSubmit={form.handleSubmit(submit)} aria-labelledby="two-factor-title">
      <FieldGroup>
        <div className="space-y-1">
          <h2 id="two-factor-title" className="flex items-center gap-2 text-lg font-semibold">
            <ShieldCheckIcon className="text-primary size-5" aria-hidden="true" />
            Two-step verification
          </h2>
          <p className="text-muted-foreground text-sm">
            {recoveryMode
              ? 'Enter one of the recovery codes you saved when you turned on two-step verification. Each code works once.'
              : 'Open your authenticator app and enter the 6-digit code for SalesHub.'}
          </p>
        </div>

        {locked ? (
          <div
            role="alert"
            className="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-sm text-amber-800 dark:text-amber-300"
          >
            <ClockIcon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p>
              Too many attempts. Try again in{' '}
              <span className="tabular font-semibold">
                {secondsLeft} {secondsLeft === 1 ? 'second' : 'seconds'}
              </span>
              .
            </p>
          </div>
        ) : (
          <FormRootError form={form} />
        )}

        {recoveryMode ? (
          <TextField
            key="recovery"
            control={form.control}
            name="recovery_code"
            label="Recovery code"
            placeholder="XXXXX-XXXXX"
            autoComplete="off"
            spellCheck={false}
            autoFocus
          />
        ) : (
          <OtpCodeField
            key="code"
            control={form.control}
            name="code"
            label="Authentication code"
            autoFocus
            onComplete={() => {
              if (!pending && !locked) void form.handleSubmit(submit)()
            }}
          />
        )}

        <Button type="submit" size="lg" className="w-full" disabled={pending || locked}>
          {pending ? <Spinner /> : null}
          {pending ? 'Verifying…' : 'Verify'}
        </Button>

        <div className="flex flex-wrap items-center justify-between gap-2">
          <Button type="button" variant="ghost" size="sm" onClick={() => onRestart()}>
            <ArrowLeftIcon aria-hidden="true" />
            Back to sign in
          </Button>
          <Button type="button" variant="link" size="sm" onClick={toggleMethod}>
            {recoveryMode ? 'Use your authenticator app' : 'Use a recovery code instead'}
          </Button>
        </div>
      </FieldGroup>
    </form>
  )
}
