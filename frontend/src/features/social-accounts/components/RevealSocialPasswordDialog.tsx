import { useCallback, useEffect } from 'react'
import { KeyRoundIcon } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Spinner } from '@/components/ui/spinner'
import type { SocialAccount } from '../types'
import { RevealedValues } from './reveal'
import { REVEAL_AUDIT_NOTICE, revealErrorMessage, useRevealCredentials } from './reveal-utils'

interface RevealSocialPasswordDialogProps {
  account: SocialAccount | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

/**
 * Reveals one social account's password (`social-accounts.reveal-credentials`). Values are
 * dropped when the dialog closes and after 30 seconds.
 */
export function RevealSocialPasswordDialog({
  account,
  open,
  onOpenChange,
}: RevealSocialPasswordDialogProps) {
  const reveal = useRevealCredentials(`/social-accounts/${account?.id ?? 0}`)
  const { reset, mutate } = reveal

  useEffect(() => {
    if (!open) reset()
  }, [open, reset])

  const hide = useCallback(() => reset(), [reset])

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Reveal password</DialogTitle>
          <DialogDescription>
            {account ? `${account.platform.label} account @${account.username}. ` : null}
            {REVEAL_AUDIT_NOTICE}
          </DialogDescription>
        </DialogHeader>

        {reveal.isError ? (
          <div role="alert" className="text-destructive text-sm">
            {revealErrorMessage(reveal.error)}
          </div>
        ) : null}

        {reveal.data ? (
          <RevealedValues
            scope={`social-${account?.id}`}
            onHide={hide}
            entries={[{ key: 'password', label: 'Password', value: reveal.data.password ?? null }]}
          />
        ) : (
          <div className="flex justify-end">
            <Button
              disabled={reveal.isPending || !account?.has_password}
              onClick={() => mutate(['password'])}
            >
              {reveal.isPending ? <Spinner /> : <KeyRoundIcon aria-hidden="true" />}
              Reveal password
            </Button>
          </div>
        )}
        {account && !account.has_password ? (
          <p className="text-muted-foreground text-sm">No password is stored for this account.</p>
        ) : null}
      </DialogContent>
    </Dialog>
  )
}
