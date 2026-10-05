import { useState, type ReactNode } from 'react'
import { ClockIcon, GaugeIcon, MapPinIcon, PencilIcon, Trash2Icon } from 'lucide-react'
import { useNavigate, useParams } from 'react-router'
import { isApiError } from '@/api/errors'
import { paths } from '@/app/paths'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { StatusBadge } from '@/components/data-display/StatusBadge'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { ErrorState } from '@/components/layout/ErrorState'
import { CardSkeleton } from '@/components/layout/LoadingSkeleton'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useAuth } from '@/features/auth/AuthProvider'
import { Can } from '@/features/auth/Can'
import { formatRelative } from '@/lib/format'
import { useDeletePlatformAccount, usePlatformAccount } from '../api'
import { LinkedSocialAccounts } from '../components/LinkedSocialAccounts'
import { PlatformAccountFormSheet } from '../components/PlatformAccountFormSheet'
import { AssignWorkstationDialog, ChangeStandingDialog } from '../components/PlatformAccountDialogs'
import { RevealCredentialsPanel } from '../components/RevealCredentialsPanel'
import type { PlatformAccount } from '../types'
import { cn } from '@/lib/utils'

function Detail({
  label,
  children,
  className,
}: {
  label: string
  children: ReactNode
  className?: string
}) {
  return (
    <div className={cn('space-y-0.5', className)}>
      <dt className="text-muted-foreground text-xs">{label}</dt>
      <dd className="text-sm break-words">{children ?? '—'}</dd>
    </div>
  )
}

function Stored({ value }: { value: boolean }) {
  return value ? <span>Set</span> : <span className="text-muted-foreground">Not set</span>
}

function PendingChangeBanner({ account }: { account: PlatformAccount }) {
  const pending = account.pending_change
  if (!pending) return null
  return (
    <div
      role="status"
      className="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
    >
      <ClockIcon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
      <p>
        <strong>{pending.action.label}</strong> is pending approval, requested by{' '}
        {pending.requested_by.name} {formatRelative(pending.requested_at)}
        {pending.fields.length > 0 ? ` (${pending.fields.join(', ')})` : ''}. Editing is paused
        until a reviewer decides.
      </p>
    </div>
  )
}

export default function PlatformAccountDetailPage() {
  const { platformAccountId } = useParams()
  const id = Number(platformAccountId)
  const navigate = useNavigate()
  const { can, canAny } = useAuth()
  const query = usePlatformAccount(id)
  const remove = useDeletePlatformAccount()

  const [editing, setEditing] = useState(false)
  const [assigning, setAssigning] = useState(false)
  const [changingStanding, setChangingStanding] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)

  if (query.isPending) {
    return (
      <div className="space-y-4" role="status" aria-label="Loading platform account">
        <CardSkeleton />
        <CardSkeleton />
      </div>
    )
  }

  if (query.isError) {
    const forbidden = isApiError(query.error) && query.error.isForbidden
    return (
      <ErrorState
        title={forbidden ? "You don't have access to this record" : 'Could not load the account'}
        error={forbidden ? undefined : query.error}
        onRetry={forbidden ? undefined : () => void query.refetch()}
      />
    )
  }

  const account = query.data
  const hasPendingChange = Boolean(account.pending_change)
  const canEdit = canAny(['platform-accounts.update', 'platform-accounts.request-change'])
  const canChangeStanding = canAny([
    'platform-accounts.change-standing',
    'platform-accounts.request-change',
  ])
  const canDelete = canAny(['platform-accounts.delete', 'platform-accounts.request-change'])
  const deleteNeedsApproval = !can('platform-accounts.delete')

  return (
    <div className="space-y-6">
      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-3">
            <span className="break-all">{account.email}</span>
            <StatusBadge kind="accountStanding" value={account.standing} />
          </span>
        }
        description={
          <>
            {account.workstation
              ? `Workstation ${account.workstation.code}${
                  account.workstation.team ? ` · ${account.workstation.team.name}` : ''
                }`
              : 'Not assigned to a workstation'}
            {account.batch_date ? ` · Batch ${account.batch_date}` : ''}
          </>
        }
        actions={
          <>
            {canEdit ? (
              <Button
                variant="outline"
                disabled={hasPendingChange}
                onClick={() => setEditing(true)}
              >
                <PencilIcon aria-hidden="true" />
                {can('platform-accounts.update') ? 'Edit' : 'Request edit'}
              </Button>
            ) : null}
            <Can permission="platform-accounts.assign">
              <Button variant="outline" onClick={() => setAssigning(true)}>
                <MapPinIcon aria-hidden="true" />
                Assign workstation
              </Button>
            </Can>
            {canChangeStanding ? (
              <Button
                variant="outline"
                disabled={hasPendingChange}
                onClick={() => setChangingStanding(true)}
              >
                <GaugeIcon aria-hidden="true" />
                Change standing
              </Button>
            ) : null}
            {canDelete ? (
              <Button
                variant="outline"
                disabled={hasPendingChange}
                onClick={() => setConfirmDelete(true)}
              >
                <Trash2Icon aria-hidden="true" />
                {deleteNeedsApproval ? 'Request deletion' : 'Delete'}
              </Button>
            ) : null}
          </>
        }
      />

      <PendingChangeBanner account={account} />

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
        <Card>
          <CardHeader>
            <CardTitle>Details</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="grid gap-4 sm:grid-cols-2">
              <Detail label="Standing">
                <StatusBadge kind="accountStanding" value={account.standing} />
                {account.standing_changed_at ? (
                  <span className="text-muted-foreground ml-2 text-xs">
                    since <RelativeTime value={account.standing_changed_at} />
                  </span>
                ) : null}
              </Detail>
              <Detail label="Workstation">
                {account.workstation ? (
                  <>
                    {account.workstation.code}
                    {account.workstation.team ? ` (${account.workstation.team.name})` : ''}
                  </>
                ) : (
                  <span className="text-muted-foreground">Unassigned</span>
                )}
              </Detail>
              <Detail label="Batch date">
                <RelativeTime value={account.batch_date} display="date" />
              </Detail>
              <Detail label="Assigned">
                <RelativeTime value={account.assigned_at} />
              </Detail>
              <Detail label="Discord email">{account.discord_email}</Detail>
              <Detail label="Discord username">{account.discord_username}</Detail>
              <Detail label="Discord created">
                <RelativeTime value={account.discord_created_on} display="date" />
              </Detail>
              <Detail label="Recovery email">{account.recovery_email}</Detail>
              <Detail label="Email password">
                <Stored value={account.has_email_password} />
              </Detail>
              <Detail label="Discord password">
                <Stored value={account.has_discord_password} />
              </Detail>
              <Detail label="Recovery phone">
                <Stored value={account.has_recovery_phone} />
              </Detail>
              <Detail label="Phone holder name">
                <Stored value={account.has_phone_holder_name} />
              </Detail>
              <Detail label="Notes" className="sm:col-span-2">
                <span className="whitespace-pre-wrap">{account.notes}</span>
              </Detail>
            </dl>
          </CardContent>
        </Card>

        <Can permission="platform-accounts.reveal-credentials">
          <RevealCredentialsPanel key={account.id} account={account} />
        </Can>
      </div>

      <LinkedSocialAccounts account={account} />

      <PlatformAccountFormSheet open={editing} onOpenChange={setEditing} account={account} />
      <AssignWorkstationDialog account={account} open={assigning} onOpenChange={setAssigning} />
      <ChangeStandingDialog
        account={account}
        open={changingStanding}
        onOpenChange={setChangingStanding}
      />
      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={
          deleteNeedsApproval
            ? 'Request deletion of this account?'
            : 'Delete this platform account?'
        }
        description={
          deleteNeedsApproval
            ? `A reviewer must approve deleting ${account.email} before it is removed.`
            : `${account.email} and its social accounts will be removed from the inventory.`
        }
        confirmLabel={deleteNeedsApproval ? 'Send request' : 'Delete account'}
        destructive
        pending={remove.isPending}
        onConfirm={async () => {
          const result = await remove.mutateAsync(account.id)
          if (result.kind === 'applied') void navigate(paths.platformAccounts)
        }}
      />
    </div>
  )
}
