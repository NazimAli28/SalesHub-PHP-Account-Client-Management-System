import type { ReactNode } from 'react'
import { ArrowDownRightIcon, ArrowRightIcon, ArrowUpRightIcon, MinusIcon } from 'lucide-react'
import { Link } from 'react-router'
import { paths } from '@/app/paths'
import { MoneyText } from '@/components/data-display/MoneyText'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { formatNumber } from '@/lib/format'
import type { Kpi, OverviewKpis } from '../types'

interface DeltaProps {
  changePct: number | null
  /** Human name of the comparison window, e.g. "previous 30 days". */
  comparedTo: string
}

/** Arrow, percentage and screen reader text such as "up 12% vs previous 30 days". */
export function Delta({ changePct, comparedTo }: DeltaProps) {
  if (changePct === null) {
    return (
      <p className="text-muted-foreground flex items-center gap-1 text-xs">
        <MinusIcon className="size-3.5" aria-hidden="true" />
        No data in {comparedTo}
      </p>
    )
  }
  const direction = changePct > 0 ? 'up' : changePct < 0 ? 'down' : 'flat'
  const Icon =
    direction === 'up' ? ArrowUpRightIcon : direction === 'down' ? ArrowDownRightIcon : MinusIcon
  const amount = `${Math.abs(changePct).toLocaleString('en-US', { maximumFractionDigits: 1 })}%`
  const text =
    direction === 'flat' ? `unchanged vs ${comparedTo}` : `${direction} ${amount} vs ${comparedTo}`
  return (
    <p
      className={cn(
        'flex items-center gap-1 text-xs font-medium',
        direction === 'up' && 'text-emerald-700 dark:text-emerald-400',
        direction === 'down' && 'text-destructive',
        direction === 'flat' && 'text-muted-foreground',
      )}
    >
      <Icon className="size-3.5" aria-hidden="true" />
      <span className="sr-only">{text}</span>
      <span aria-hidden="true">
        {direction === 'flat' ? '0%' : amount}{' '}
        <span className="text-muted-foreground font-normal">vs {comparedTo}</span>
      </span>
    </p>
  )
}

interface StatCardProps {
  label: string
  value: ReactNode
  footer: ReactNode
  href?: string
}

function StatCard({ label, value, footer, href }: StatCardProps) {
  return (
    <Card size="sm" className="relative">
      <CardContent className="space-y-1">
        <p className="text-muted-foreground flex items-center justify-between text-sm">
          {href ? (
            <Link
              to={href}
              className="after:absolute after:inset-0 hover:underline focus-visible:underline"
            >
              {label}
            </Link>
          ) : (
            label
          )}
          {href ? <ArrowRightIcon className="size-3.5" aria-hidden="true" /> : null}
        </p>
        <p className="tabular text-2xl font-semibold tracking-tight">{value}</p>
        <div className="min-h-4">{footer}</div>
      </CardContent>
    </Card>
  )
}

export function KpiCardsSkeleton() {
  return (
    <div
      className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
      role="status"
      aria-label="Loading key figures"
    >
      {Array.from({ length: 8 }, (_, i) => (
        <Card key={i} size="sm">
          <CardContent className="space-y-2">
            <Skeleton className="h-4 w-24" />
            <Skeleton className="h-8 w-28" />
            <Skeleton className="h-3 w-32" />
          </CardContent>
        </Card>
      ))}
    </div>
  )
}

interface KpiCardsProps {
  kpis: OverviewKpis
  comparedTo: string
}

export function KpiCards({ kpis, comparedTo }: KpiCardsProps) {
  const delta = (kpi: Kpi<unknown>) => <Delta changePct={kpi.change_pct} comparedTo={comparedTo} />
  const { overdue_payments: overdue, pending_approvals: approvals } = kpis

  return (
    <section aria-label="Key figures" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <StatCard
        label="Revenue collected"
        value={<MoneyText money={kpis.revenue_collected.value} />}
        footer={delta(kpis.revenue_collected)}
      />
      <StatCard
        label="Won value"
        value={<MoneyText money={kpis.won_value.value} />}
        footer={delta(kpis.won_value)}
      />
      <StatCard
        label="New leads"
        value={formatNumber(kpis.new_leads.value)}
        footer={delta(kpis.new_leads)}
        href={paths.leads}
      />
      <StatCard
        label="Lead to won rate"
        value={`${kpis.conversion_rate.value.toLocaleString('en-US', { maximumFractionDigits: 1 })}%`}
        footer={delta(kpis.conversion_rate)}
      />
      <StatCard
        label="Average order value"
        value={<MoneyText money={kpis.average_order_value.value} />}
        footer={delta(kpis.average_order_value)}
        href={paths.orders}
      />
      <StatCard
        label="Overdue payments"
        value={formatNumber(overdue.count)}
        footer={
          <p
            className={cn(
              'text-xs',
              overdue.count > 0 ? 'text-destructive' : 'text-muted-foreground',
            )}
          >
            {overdue.count > 0 ? (
              <>
                <MoneyText money={overdue.amount} /> outstanding
              </>
            ) : (
              'Nothing overdue'
            )}
          </p>
        }
        href={paths.payments}
      />
      <StatCard
        label="Approvals waiting"
        value={formatNumber(approvals.reviewable)}
        footer={
          <p className="text-muted-foreground text-xs">
            {approvals.submitted} of your requests pending
          </p>
        }
        href={paths.approvals}
      />
      <StatCard
        label="Active clients"
        value={formatNumber(kpis.active_clients)}
        footer={<p className="text-muted-foreground text-xs">Right now</p>}
        href={paths.clients}
      />
    </section>
  )
}
