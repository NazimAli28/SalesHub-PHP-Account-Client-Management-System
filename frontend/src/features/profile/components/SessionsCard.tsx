import { useState } from 'react'
import { LaptopIcon } from 'lucide-react'
import { ErrorState } from '@/components/layout/ErrorState'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { securityKeys, signOutOtherSessions, useSessionsQuery } from '../api'
import { ConfirmPasswordDialog } from './ConfirmPasswordDialog'

/** Browsers signed in to this account, with "sign out everywhere else". */
export function SessionsCard() {
  const sessions = useSessionsQuery()
  const [confirming, setConfirming] = useState(false)
  const others = (sessions.data ?? []).filter((session) => !session.is_current).length

  return (
    <Card>
      <CardHeader>
        <CardTitle>Active sessions</CardTitle>
        <CardDescription>
          Browsers signed in to your account. Sign the others out if you do not recognise one.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {sessions.isPending ? (
          <div className="space-y-2" aria-label="Loading sessions">
            <Skeleton className="h-12 w-full" />
            <Skeleton className="h-12 w-full" />
          </div>
        ) : sessions.isError ? (
          <ErrorState
            title="We could not load your sessions"
            error={sessions.error}
            onRetry={() => void sessions.refetch()}
            className="py-6"
          />
        ) : (
          <ul aria-label="Active sessions" className="divide-y rounded-lg border">
            {sessions.data.map((session) => (
              <li key={session.id} className="flex items-center gap-3 px-3 py-2.5">
                <LaptopIcon className="text-muted-foreground size-5 shrink-0" aria-hidden="true" />
                <div className="min-w-0 flex-1">
                  <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                    {session.device}
                    {session.is_current ? <Badge variant="secondary">This device</Badge> : null}
                  </p>
                  <p className="text-muted-foreground text-xs">
                    {session.ip_address ?? 'Unknown IP'} · active{' '}
                    <RelativeTime value={session.last_active_at} />
                  </p>
                </div>
              </li>
            ))}
          </ul>
        )}

        <Button
          type="button"
          variant="outline"
          onClick={() => setConfirming(true)}
          disabled={!sessions.isSuccess || others === 0}
        >
          Sign out other sessions
        </Button>
      </CardContent>

      <ConfirmPasswordDialog
        open={confirming}
        onOpenChange={setConfirming}
        title="Sign out other sessions?"
        description="Every other browser signed in to your account is signed out. Enter your password to confirm."
        submitLabel="Sign out others"
        action={signOutOtherSessions}
        successMessage={(data) =>
          data.revoked === 1
            ? 'Signed out 1 other session.'
            : `Signed out ${data.revoked} other sessions.`
        }
        invalidate={[securityKeys.sessions()]}
      />
    </Card>
  )
}
