import type { ApprovalItem } from '../api'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { cn } from '@/lib/utils'

interface Step {
  key: string
  title: string
  detail?: string | null
  at: string | null
  tone: 'done' | 'bad' | 'muted'
}

function buildSteps(approval: ApprovalItem): Step[] {
  const steps: Step[] = [
    {
      key: 'submitted',
      title: `Submitted by ${approval.requester?.name ?? 'a user'}`,
      detail: approval.reason,
      at: approval.created_at,
      tone: 'done',
    },
  ]
  const status = approval.status.value
  if (status === 'cancelled') {
    steps.push({
      key: 'cancelled',
      title: 'Cancelled by the requester',
      at: approval.reviewed_at ?? approval.updated_at,
      tone: 'muted',
    })
  } else if (status === 'approved' || status === 'rejected' || status === 'failed') {
    const verb = status === 'rejected' ? 'Rejected' : 'Approved'
    steps.push({
      key: 'reviewed',
      title: `${verb}${approval.reviewer ? ` by ${approval.reviewer.name}` : ''}`,
      detail: approval.review_comment,
      at: approval.reviewed_at,
      tone: status === 'rejected' ? 'bad' : 'done',
    })
    if (status === 'approved') {
      steps.push({ key: 'applied', title: 'Change applied', at: approval.applied_at, tone: 'done' })
    }
    if (status === 'failed') {
      steps.push({
        key: 'failed',
        title: 'Could not be applied',
        detail: approval.failure_message,
        at: approval.updated_at,
        tone: 'bad',
      })
    }
  } else {
    steps.push({ key: 'waiting', title: 'Waiting for review', at: null, tone: 'muted' })
  }
  return steps
}

const DOT = {
  done: 'bg-emerald-500',
  bad: 'bg-red-500',
  muted: 'bg-muted-foreground/40',
} as const

/** Vertical list: submitted, reviewed, applied. */
export function ApprovalTimeline({ approval }: { approval: ApprovalItem }) {
  return (
    <ol className="space-y-4" aria-label="Timeline">
      {buildSteps(approval).map((step) => (
        <li key={step.key} className="flex gap-3">
          <span
            aria-hidden="true"
            className={cn('mt-1.5 size-2.5 shrink-0 rounded-full', DOT[step.tone])}
          />
          <div className="min-w-0 space-y-0.5 text-sm">
            <p className="font-medium">{step.title}</p>
            {step.at ? (
              <RelativeTime value={step.at} className="text-muted-foreground text-xs" />
            ) : null}
            {step.detail ? (
              <p className="text-muted-foreground break-words whitespace-pre-wrap">{step.detail}</p>
            ) : null}
          </div>
        </li>
      ))}
    </ol>
  )
}
