import { useState } from 'react'
import { CheckIcon, ExternalLinkIcon, XIcon } from 'lucide-react'
import { Link, useParams } from 'react-router'
import { isApiError } from '@/api/errors'
import { StatusBadge } from '@/components/data-display/StatusBadge'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { EmptyState } from '@/components/layout/EmptyState'
import { ErrorState } from '@/components/layout/ErrorState'
import { CardSkeleton } from '@/components/layout/LoadingSkeleton'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { paths } from '@/app/paths'
import {
  useApproval,
  useApproveApproval,
  useCancelApproval,
  useRejectApproval,
  type ApprovalItem,
} from '../api'
import { ApprovalDiffTable } from '../components/ApprovalDiffTable'
import { ApprovalTimeline } from '../components/ApprovalTimeline'
import { DecisionDialog } from '../components/DecisionDialog'
import { summarize, targetLink, typeMeta } from '../format'

const CONFLICT_MESSAGE =
  'This request was already decided, or the record changed since it was submitted. The details below are up to date.'

export default function ApprovalDetailPage() {
  const { approvalId } = useParams()
  const id = Number(approvalId)
  const approvalQuery = useApproval(id)
  const approve = useApproveApproval()
  const reject = useRejectApproval()
  const cancel = useCancelApproval()

  const [dialog, setDialog] = useState<'approve' | 'reject' | 'cancel' | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  if (approvalQuery.isPending) {
    return (
      <div className="space-y-4" role="status" aria-label="Loading approval">
        <CardSkeleton />
        <CardSkeleton />
      </div>
    )
  }
  if (approvalQuery.isError) {
    const error = approvalQuery.error
    const denied = isApiError(error) && (error.isForbidden || error.isNotFound)
    return (
      <ErrorState
        title={denied ? "You don't have access to this request" : 'Could not load this request'}
        error={denied ? undefined : error}
        onRetry={denied ? undefined : () => approvalQuery.refetch()}
      />
    )
  }

  const approval: ApprovalItem = approvalQuery.data
  const meta = typeMeta(approval)
  const TypeIcon = meta.icon
  const link = targetLink(approval)
  const canReview = approval.can?.review === true
  const canCancel = approval.can?.cancel === true

  /** 409 and 403 are explained on the page; anything else keeps the dialog open for a retry. */
  async function run(action: () => Promise<unknown>) {
    setNotice(null)
    try {
      await action()
    } catch (error) {
      if (isApiError(error) && error.isConflict) {
        setNotice(CONFLICT_MESSAGE)
        await approvalQuery.refetch()
        return
      }
      if (isApiError(error) && error.isForbidden) {
        setNotice('You are not allowed to decide this request.')
        await approvalQuery.refetch()
        return
      }
      throw error
    }
  }

  const description = `Requested by ${approval.requester?.name ?? 'a user'}`

  return (
    <div className="space-y-6">
      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-3">
            <TypeIcon className="text-muted-foreground size-6" aria-hidden="true" />
            {summarize(approval)}
            <StatusBadge kind="approvalStatus" value={approval.status} />
          </span>
        }
        description={description}
        actions={
          <>
            {link ? (
              <Button variant="outline" asChild>
                <Link to={link.to}>
                  <ExternalLinkIcon aria-hidden="true" />
                  {link.label}
                </Link>
              </Button>
            ) : null}
            {canCancel ? (
              <Button variant="outline" onClick={() => setDialog('cancel')}>
                Cancel request
              </Button>
            ) : null}
            {canReview ? (
              <>
                <Button variant="outline" onClick={() => setDialog('reject')}>
                  <XIcon aria-hidden="true" />
                  Reject
                </Button>
                <Button onClick={() => setDialog('approve')}>
                  <CheckIcon aria-hidden="true" />
                  Approve
                </Button>
              </>
            ) : null}
          </>
        }
      />

      {notice ? (
        <div
          role="alert"
          className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"
        >
          {notice}
        </div>
      ) : null}

      {approval.status.value === 'failed' && approval.failure_message ? (
        <div
          role="alert"
          className="border-destructive/40 bg-destructive/5 rounded-lg border p-3 text-sm"
        >
          <p className="font-medium">This change could not be applied</p>
          <p className="text-muted-foreground">{approval.failure_message}</p>
        </div>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <Card>
          <CardHeader>
            <CardTitle>Changes</CardTitle>
          </CardHeader>
          <CardContent>
            {approval.diff.length > 0 ? (
              <ApprovalDiffTable
                rows={approval.diff}
                applied={approval.status.value === 'approved'}
              />
            ) : (
              <EmptyState
                title="No field changes"
                description="This request carries no field values."
              />
            )}
          </CardContent>
        </Card>

        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Details</CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt className="text-muted-foreground">Type</dt>
                <dd>{meta.label}</dd>
                <dt className="text-muted-foreground">Action</dt>
                <dd>{approval.action.label}</dd>
                <dt className="text-muted-foreground">Requester</dt>
                <dd>{approval.requester?.name ?? '—'}</dd>
                <dt className="text-muted-foreground">Requested</dt>
                <dd>
                  <RelativeTime value={approval.created_at} />
                </dd>
                <dt className="text-muted-foreground">Reviewer</dt>
                <dd>{approval.reviewer?.name ?? '—'}</dd>
                {approval.reason ? (
                  <>
                    <dt className="text-muted-foreground">Reason</dt>
                    <dd className="break-words whitespace-pre-wrap">{approval.reason}</dd>
                  </>
                ) : null}
              </dl>
              <Button variant="link" className="mt-3 h-auto p-0" asChild>
                <Link to={paths.approvals}>Back to approvals</Link>
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Timeline</CardTitle>
            </CardHeader>
            <CardContent>
              <ApprovalTimeline approval={approval} />
            </CardContent>
          </Card>
        </div>
      </div>

      <DecisionDialog
        mode="approve"
        open={dialog === 'approve'}
        onOpenChange={(open) => setDialog(open ? 'approve' : null)}
        subject={summarize(approval)}
        pending={approve.isPending}
        onSubmit={(comment) => run(() => approve.mutateAsync({ id, comment }))}
      />
      <DecisionDialog
        mode="reject"
        open={dialog === 'reject'}
        onOpenChange={(open) => setDialog(open ? 'reject' : null)}
        subject={summarize(approval)}
        pending={reject.isPending}
        onSubmit={(comment) => run(() => reject.mutateAsync({ id, comment }))}
      />
      <ConfirmDialog
        open={dialog === 'cancel'}
        onOpenChange={(open) => setDialog(open ? 'cancel' : null)}
        title="Cancel this request?"
        description="The change will not be applied. You can submit a new request later."
        confirmLabel="Cancel request"
        cancelLabel="Keep request"
        destructive
        pending={cancel.isPending}
        onConfirm={() => run(() => cancel.mutateAsync(id))}
      />
    </div>
  )
}
