import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { ShieldCheckIcon, ShieldOffIcon } from 'lucide-react'
import { useApiMutation } from '@/api/use-api-mutation'
import { FormRootError } from '@/components/form'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { FieldGroup } from '@/components/ui/field'
import { Spinner } from '@/components/ui/spinner'
import { authKeys } from '@/features/auth/api'
import { OtpCodeField } from '@/features/auth/components/OtpCodeField'
import { otpCodeSchema, type OtpCodeValues } from '@/features/auth/schemas'
import {
  confirmTwoFactor,
  regenerateRecoveryCodes,
  showRecoveryCodes,
  startTwoFactorSetup,
  type TwoFactorSetup,
} from '../api'
import { ConfirmPasswordDialog } from './ConfirmPasswordDialog'
import { DisableTwoFactorDialog } from './DisableTwoFactorDialog'
import { RecoveryCodesPanel } from './RecoveryCodesPanel'

type PasswordAction = 'disable' | 'show-codes' | 'regenerate-codes'

function SetupStep({
  setup,
  onConfirmed,
  onCancel,
}: {
  setup: TwoFactorSetup
  onConfirmed: (codes: string[]) => void
  onCancel: () => void
}) {
  const form = useForm<OtpCodeValues>({
    resolver: zodResolver(otpCodeSchema),
    defaultValues: { code: '' },
  })

  const confirm = useApiMutation({
    mutationFn: confirmTwoFactor,
    form,
    successMessage: 'Two-step verification is on.',
    invalidate: [authKeys.me],
    onSuccess: (data) => onConfirmed(data.recovery_codes),
    onError: () => form.setValue('code', ''),
  })

  return (
    <div className="space-y-5">
      <ol className="text-muted-foreground list-decimal space-y-1 pl-5 text-sm">
        <li>Open an authenticator app (Google Authenticator, 1Password, Authy, …).</li>
        <li>Scan the QR code, or type the setup key by hand.</li>
        <li>Enter the 6-digit code the app shows.</li>
      </ol>
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
        <img
          src={setup.qr_code}
          alt="QR code to add SalesHub to your authenticator app"
          width={176}
          height={176}
          className="size-44 shrink-0 rounded-lg border bg-white p-2"
        />
        <div className="min-w-0 space-y-1">
          <p className="text-sm font-medium">Setup key</p>
          <code
            aria-label="Setup key"
            className="bg-muted block rounded-md px-2 py-1.5 font-mono text-sm break-all"
          >
            {setup.secret}
          </code>
        </div>
      </div>
      <form noValidate onSubmit={form.handleSubmit((values) => confirm.mutate(values))}>
        <FieldGroup>
          <FormRootError form={form} />
          <OtpCodeField control={form.control} name="code" label="Code from the app" />
          <div className="flex flex-wrap gap-2">
            <Button type="submit" disabled={confirm.isPending}>
              {confirm.isPending ? <Spinner /> : null}
              Confirm and turn on
            </Button>
            <Button type="button" variant="ghost" onClick={onCancel} disabled={confirm.isPending}>
              Cancel
            </Button>
          </div>
        </FieldGroup>
      </form>
    </div>
  )
}

/** Turn TOTP two-step verification on or off, and manage the recovery codes. */
export function TwoFactorCard({ enabled, demoMode }: { enabled: boolean; demoMode: boolean }) {
  const [setup, setSetup] = useState<TwoFactorSetup | null>(null)
  const [codes, setCodes] = useState<string[] | null>(null)
  const [passwordAction, setPasswordAction] = useState<PasswordAction | null>(null)

  const start = useApiMutation({
    mutationFn: () => startTwoFactorSetup(),
    successMessage: false,
    onSuccess: (data) => {
      setCodes(null)
      setSetup(data)
    },
  })

  const dialogOpen = (action: PasswordAction) => passwordAction === action
  const closeDialog = (open: boolean) => {
    if (!open) setPasswordAction(null)
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between gap-2">
          <CardTitle>Two-step verification</CardTitle>
          {enabled ? (
            <Badge className="bg-emerald-500/15 text-emerald-700 dark:text-emerald-300">
              <ShieldCheckIcon aria-hidden="true" />
              On
            </Badge>
          ) : (
            <Badge variant="secondary">
              <ShieldOffIcon aria-hidden="true" />
              Off
            </Badge>
          )}
        </div>
        <CardDescription>
          Ask for a code from an authenticator app after your password, so a stolen password alone
          is not enough to sign in.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-5">
        {codes ? (
          <div className="space-y-3">
            <RecoveryCodesPanel codes={codes} />
            <Button type="button" onClick={() => setCodes(null)}>
              I saved my codes
            </Button>
          </div>
        ) : setup && !enabled ? (
          <SetupStep
            setup={setup}
            onCancel={() => setSetup(null)}
            onConfirmed={(recoveryCodes) => {
              setSetup(null)
              setCodes(recoveryCodes)
            }}
          />
        ) : enabled ? (
          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="outline" onClick={() => setPasswordAction('show-codes')}>
              Show recovery codes
            </Button>
            <Button
              type="button"
              variant="outline"
              onClick={() => setPasswordAction('regenerate-codes')}
            >
              Regenerate recovery codes
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={() => setPasswordAction('disable')}
            >
              Turn off
            </Button>
          </div>
        ) : (
          <Button
            type="button"
            onClick={() => start.mutate()}
            disabled={demoMode || start.isPending}
          >
            {start.isPending ? <Spinner /> : null}
            Turn on two-step verification
          </Button>
        )}
      </CardContent>

      <DisableTwoFactorDialog
        open={dialogOpen('disable')}
        onOpenChange={closeDialog}
        onDisabled={() => setCodes(null)}
      />
      <ConfirmPasswordDialog
        open={dialogOpen('show-codes')}
        onOpenChange={closeDialog}
        title="Show recovery codes"
        description="Enter your password to see your unused recovery codes."
        submitLabel="Show codes"
        action={showRecoveryCodes}
        onConfirmed={(data) => setCodes(data.recovery_codes)}
      />
      <ConfirmPasswordDialog
        open={dialogOpen('regenerate-codes')}
        onOpenChange={closeDialog}
        title="Regenerate recovery codes?"
        description="Your current recovery codes stop working. Enter your password to confirm."
        submitLabel="Regenerate"
        action={regenerateRecoveryCodes}
        successMessage="New recovery codes created."
        onConfirmed={(data) => setCodes(data.recovery_codes)}
      />
    </Card>
  )
}
