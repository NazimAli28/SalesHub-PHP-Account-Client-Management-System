import { useState, type ReactNode } from 'react'
import { CalendarCheckIcon, CircleCheckBigIcon, ClipboardListIcon, TargetIcon } from 'lucide-react'
import { Link } from 'react-router'
import type { Lead } from '@/api/types'
import { MoneyText } from '@/components/data-display/MoneyText'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { StatusBadge } from '@/components/data-display/StatusBadge'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { EmptyState } from '@/components/layout/EmptyState'
import { ErrorState } from '@/components/layout/ErrorState'
import { PageHeader } from '@/components/layout/PageHeader'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { detailPath, paths } from '@/app/paths'
import { usePendingApprovalsCount } from '@/features/approvals/api'
import { useAuth } from '@/features/auth/AuthProvider'
import { Can } from '@/features/auth/Can'
import { toIsoDate } from '@/lib/format'
import { VIEW_ANY } from '@/lib/permissions'
import { useDuePayments, useFollowUpLeads, useMarkPaymentPaid, type DuePayment } from '../api'

interface SectionProps {
  title: string
  /** Shown in a badge next to the title once loaded. */
  count: number | undefined
  isPending: boolean
  error?: unknown
  onRetry?: () => void
  emptyTitle: string
  emptyDescription: string
  footer?: ReactNode
  children: ReactNode
}

/** A titled card with loading, error and empty states. */
function Section({
  title,
  count,
  isPending,
  error,
  onRetry,
  emptyTitle,
  emptyDescription,
  footer,
  children,
}: SectionProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          {title}
          {count !== undefined ? (
            <Badge variant="secondary" aria-label={`${count} items`}>
              {count}
            </Badge>
          ) : null}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {isPending ? (
          <div className="space-y-3" role="status" aria-label={`Loading ${title}`}>
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
          </div>
        ) : error !== undefined ? (
          <ErrorState error={error} onRetry={onRetry} className="py-6" />
        ) : count === 0 ? (
          <EmptyState
            icon={CircleCheckBigIcon}
            title={emptyTitle}
            description={emptyDescription}
            className="py-6"
          />
        ) : (
          children
        )}
        {footer}
      </CardContent>
    </Card>
  )
}

function greeting(now: Date): string {
  const hour = now.getHours()
  return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'
}

function PaymentRow({ payment, today }: { payment: DuePayment; today: string }) {
  const markPaid = useMarkPaymentPaid()
  const client = payment.order?.client
  const clientName = client ? (client.name ?? client.discord_username) : 'Unknown client'
  const overdue = (payment.due_date ?? today) < today
  const orderNumber = payment.order?.order_number ?? `#${payment.order_id}`

  return (
    <li className="flex flex-wrap items-center gap-x-4 gap-y-2 py-3 first:pt-0 last:pb-0">
      <div className="min-w-0 flex-1 basis-full sm:basis-0">
        <p className="truncate text-sm font-medium">{clientName}</p>
        <p className="text-muted-foreground text-xs">
          <Link
            to={detailPath.order(payment.order_id)}
            className="whitespace-nowrap hover:underline"
          >
            Order {orderNumber}
          </Link>
          {' · '}installment {payment.sequence}
        </p>
      </div>
      <div className="mr-auto text-sm sm:mr-0 sm:text-right">
        <MoneyText money={payment.amount} className="font-medium" />
        <p className={overdue ? 'text-xs font-medium text-red-600 dark:text-red-400' : 'text-xs'}>
          <RelativeTime value={payment.due_date} display="date" />
          {overdue ? ' · Overdue' : ' · Due today'}
        </p>
      </div>
      <Can anyOf={['payments.update', 'payments.request-change']}>
        <ConfirmDialog
          title="Mark this payment as paid?"
          description={`${clientName}, order ${orderNumber}. The payment is recorded as received today.`}
          confirmLabel="Mark paid"
          pending={markPaid.isPending}
          onConfirm={() => markPaid.mutateAsync(payment.id)}
          trigger={
            <Button
              variant="outline"
              size="sm"
              disabled={payment.pending_change !== null}
              aria-label={`Mark paid: ${clientName}, order ${orderNumber}`}
            >
              Mark paid
            </Button>
          }
        />
      </Can>
    </li>
  )
}

function LeadRow({ lead, today }: { lead: Lead; today: string }) {
  const overdue = (lead.next_follow_up_on ?? today) < today
  const name = lead.client?.name ?? lead.client?.discord_username ?? `Client #${lead.client_id}`
  return (
    <li className="flex flex-wrap items-center gap-x-4 gap-y-2 py-3 first:pt-0 last:pb-0">
      <div className="min-w-0 flex-1 basis-full sm:basis-0">
        <p className="truncate text-sm font-medium">{name}</p>
        {lead.last_message ? (
          <p className="text-muted-foreground truncate text-xs">{lead.last_message}</p>
        ) : null}
      </div>
      <StatusBadge kind="leadStage" value={lead.stage} />
      <div className="ml-auto text-right text-sm">
        <MoneyText money={lead.estimated_value} />
        <p className={overdue ? 'text-xs font-medium text-red-600 dark:text-red-400' : 'text-xs'}>
          <RelativeTime value={lead.next_follow_up_on} display="date" />
          {overdue ? ' · Overdue' : ' · Today'}
        </p>
      </div>
    </li>
  )
}

export default function TodayPage() {
  const { user, canAny } = useAuth()
  const [now] = useState(() => new Date())
  const today = toIsoDate(now)

  const canSeePayments = canAny(VIEW_ANY.orders)
  const canSeeLeads = canAny(VIEW_ANY.leads)
  const canSeeApprovals = canAny(VIEW_ANY.approvals)

  const payments = useDuePayments({ today, enabled: canSeePayments })
  const leads = useFollowUpLeads({ today, enabled: canSeeLeads })
  const approvals = usePendingApprovalsCount({ enabled: canSeeApprovals })

  const firstName = user?.name.split(' ')[0]
  const duePayments = payments.data?.data
  const followUps = leads.data
  const nothingVisible = !canSeePayments && !canSeeLeads && !canSeeApprovals

  return (
    <div className="space-y-6">
      <PageHeader
        title="Today"
        description={`${greeting(now)}${firstName ? `, ${firstName}` : ''}. Here is what needs you today.`}
      />

      {nothingVisible ? (
        <EmptyState
          icon={CalendarCheckIcon}
          title="Nothing to show"
          description="Your role has no daily tasks."
        />
      ) : null}

      {canSeePayments ? (
        <Section
          title="Payments due today and overdue"
          count={duePayments?.length}
          isPending={payments.isPending}
          error={payments.isError ? payments.error : undefined}
          onRetry={() => payments.refetch()}
          emptyTitle="No payments due"
          emptyDescription="Nothing is due today and nothing is overdue."
          footer={
            <Button variant="link" className="h-auto p-0" asChild>
              <Link to={paths.payments}>All payments</Link>
            </Button>
          }
        >
          <ul className="divide-y">
            {duePayments?.map((payment) => (
              <PaymentRow key={payment.id} payment={payment} today={today} />
            ))}
          </ul>
        </Section>
      ) : null}

      {canSeeLeads ? (
        <Section
          title="Lead follow-ups due today and overdue"
          count={followUps?.length}
          isPending={leads.isPending}
          error={leads.isError ? leads.error : undefined}
          onRetry={() => leads.refetch()}
          emptyTitle="No follow-ups due"
          emptyDescription="No open lead has a follow-up due today or earlier."
          footer={
            <Button variant="link" className="h-auto p-0" asChild>
              <Link to={paths.leads}>
                <TargetIcon aria-hidden="true" />
                All leads
              </Link>
            </Button>
          }
        >
          <ul className="divide-y">
            {followUps?.map((lead) => (
              <LeadRow key={lead.id} lead={lead} today={today} />
            ))}
          </ul>
        </Section>
      ) : null}

      {canSeeApprovals ? (
        <Section
          title="My pending approval requests"
          count={approvals.data?.own}
          isPending={approvals.isPending}
          error={approvals.isError ? approvals.error : undefined}
          onRetry={() => approvals.refetch()}
          emptyTitle="No requests waiting"
          emptyDescription="Everything you sent for approval has been decided."
          footer={
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
              <Button variant="link" className="h-auto p-0" asChild>
                <Link to={`${paths.approvals}?tab=mine&status=pending`}>
                  <ClipboardListIcon aria-hidden="true" />
                  View my requests
                </Link>
              </Button>
              {(approvals.data?.reviewable ?? 0) > 0 ? (
                <Button variant="link" className="h-auto p-0" asChild>
                  <Link to={`${paths.approvals}?tab=review&status=pending`}>
                    {approvals.data?.reviewable} waiting for your review
                  </Link>
                </Button>
              ) : null}
            </div>
          }
        >
          <p className="text-sm">
            You have {approvals.data?.own} {approvals.data?.own === 1 ? 'request' : 'requests'}{' '}
            waiting for a decision.
          </p>
        </Section>
      ) : null}
    </div>
  )
}
