import { useCallback, useId, useState } from 'react'
import { KeyRoundIcon, ShieldAlertIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Spinner } from '@/components/ui/spinner'
import { RevealedValues } from '@/features/social-accounts/components/reveal'
import {
  REVEAL_AUDIT_NOTICE,
  revealErrorMessage,
  useRevealCredentials,
} from '@/features/social-accounts/components/reveal-utils'
import { CREDENTIAL_FIELDS, type CredentialField, type PlatformAccount } from '../types'

/**
 * "Reveal credentials" (`platform-accounts.reveal-credentials`). The user picks the fields, the
 * API returns them (audit-logged, throttled), and they hide again after 30 seconds. Nothing is
 * ever read from the record itself: it only knows which secrets are stored.
 */
export function RevealCredentialsPanel({ account }: { account: PlatformAccount }) {
  const available = CREDENTIAL_FIELDS.filter((field) => account[field.has])
  const [selected, setSelected] = useState<Set<CredentialField>>(
    () => new Set(available.map((field) => field.key)),
  )
  const groupId = useId()
  const reveal = useRevealCredentials(`/platform-accounts/${account.id}`)
  const { reset, mutate } = reveal
  const hide = useCallback(() => reset(), [reset])

  const toggle = (key: CredentialField, checked: boolean) =>
    setSelected((current) => {
      const next = new Set(current)
      if (checked) next.add(key)
      else next.delete(key)
      return next
    })

  const chosen = available.filter((field) => selected.has(field.key))
  const entries = reveal.data
    ? CREDENTIAL_FIELDS.filter((field) => field.key in reveal.data).map((field) => ({
        key: field.key,
        label: field.label,
        value: reveal.data[field.key] ?? null,
      }))
    : []

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <KeyRoundIcon className="size-4" aria-hidden="true" />
          Credentials
        </CardTitle>
        <CardDescription>
          Stored encrypted and hidden by default. {REVEAL_AUDIT_NOTICE}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {available.length === 0 ? (
          <p className="text-muted-foreground text-sm">
            No credentials are stored for this account.
          </p>
        ) : reveal.data ? (
          <RevealedValues scope={`platform-${account.id}`} entries={entries} onHide={hide} />
        ) : (
          <>
            <fieldset className="space-y-2" aria-labelledby={`${groupId}-legend`}>
              <legend id={`${groupId}-legend`} className="text-sm font-medium">
                Choose what to reveal
              </legend>
              {available.map((field) => (
                <div key={field.key} className="flex items-center gap-2">
                  <Checkbox
                    id={`${groupId}-${field.key}`}
                    checked={selected.has(field.key)}
                    onCheckedChange={(checked) => toggle(field.key, checked === true)}
                  />
                  <Label htmlFor={`${groupId}-${field.key}`} className="font-normal">
                    {field.label}
                  </Label>
                </div>
              ))}
            </fieldset>

            {reveal.isError ? (
              <div role="alert" className="text-destructive flex items-start gap-2 text-sm">
                <ShieldAlertIcon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                {revealErrorMessage(reveal.error)}
              </div>
            ) : null}

            <Button
              disabled={reveal.isPending || chosen.length === 0}
              onClick={() => mutate(chosen.map((field) => field.key))}
            >
              {reveal.isPending ? <Spinner /> : <KeyRoundIcon aria-hidden="true" />}
              Reveal credentials
            </Button>
          </>
        )}
      </CardContent>
    </Card>
  )
}
