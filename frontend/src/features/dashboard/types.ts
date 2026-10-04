/** Response of `GET /api/analytics/overview` (backend: App\Analytics\OverviewReport + OverviewResource). */
import type { EnumValue, IsoDate, Money } from '@/api/types'

export type Bucket = 'day' | 'week' | 'month'

/** A KPI with the same measure for the previous period. `change_pct` is null when previous is 0. */
export interface Kpi<TValue> {
  value: TValue
  previous: TValue
  change_pct: number | null
}

export interface OverviewRange {
  from: IsoDate
  to: IsoDate
  previous_from: IsoDate
  previous_to: IsoDate
  bucket: Bucket
  days: number
}

export interface OverviewKpis {
  revenue_collected: Kpi<Money>
  won_value: Kpi<Money>
  won_count: Kpi<number>
  new_leads: Kpi<number>
  /** Percent, 0 to 100. */
  conversion_rate: Kpi<number>
  average_order_value: Kpi<Money>
  overdue_payments: { count: number; amount: Money }
  pending_approvals: { reviewable: number; submitted: number }
  active_clients: number
}

export interface FunnelStage {
  stage: EnumValue
  count: number
}

export interface RevenuePoint {
  /** First day of the bucket. */
  date: IsoDate
  collected_cents: number
  won_cents: number
}

export interface LeaderboardRow {
  user: { id: number; name: string }
  collected: Money
  won_leads: number
}

export interface AccountHealthRow {
  standing: EnumValue
  count: number
}

export interface UpcomingPayment {
  id: number
  order: { id: number; order_number: string | null; client_name: string | null }
  amount: Money
  due_date: IsoDate | null
}

export interface Overview {
  range: OverviewRange
  currency: string
  kpis: OverviewKpis
  funnel: FunnelStage[]
  revenue_series: RevenuePoint[]
  /** Null for sales executives and when a single person is filtered. */
  leaderboard: LeaderboardRow[] | null
  /** Null when the user cannot view platform accounts. */
  account_health: AccountHealthRow[] | null
  upcoming_payments: UpcomingPayment[]
}

export interface OverviewParams {
  from: IsoDate
  to: IsoDate
  teamId?: number | null
}
