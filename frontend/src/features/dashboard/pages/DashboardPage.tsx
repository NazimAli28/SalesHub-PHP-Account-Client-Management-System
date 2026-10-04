import { useMemo } from 'react'
import { ArrowRightIcon, CalendarCheckIcon, ClipboardCheckIcon, TargetIcon } from 'lucide-react'
import { Link, useSearchParams } from 'react-router'
import { paths } from '@/app/paths'
import { ErrorState } from '@/components/layout/ErrorState'
import { PageHeader } from '@/components/layout/PageHeader'
import { Card } from '@/components/ui/card'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { useAuth } from '@/features/auth/AuthProvider'
import { Can } from '@/features/auth/Can'
import { useTeamFilterOptions } from '@/features/teams/api'
import { formatRelative } from '@/lib/format'
import { VIEW_ANY } from '@/lib/permissions'
import { roleLabel } from '@/lib/roles'
import { cn } from '@/lib/utils'
import {
  DEFAULT_PRESET,
  RANGE_PRESETS,
  isRangePreset,
  presetInfo,
  presetRange,
  useOverview,
  type RangePreset,
} from '../api'
import { AccountHealthChart } from '../components/AccountHealthChart'
import { ChartCard } from '../components/ChartCard'
import { FunnelChart } from '../components/FunnelChart'
import { KpiCards, KpiCardsSkeleton } from '../components/KpiCards'
import { Leaderboard } from '../components/Leaderboard'
import { RevenueChart } from '../components/RevenueChart'
import { UpcomingPayments } from '../components/UpcomingPayments'

const ALL_TEAMS = 'all'

function greeting(date = new Date()): string {
  const hour = date.getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
}

function ChartSkeleton() {
  return (
    <Card className="p-4" role="status" aria-label="Loading chart">
      <Skeleton className="h-5 w-40" />
      <Skeleton className="h-52 w-full" />
    </Card>
  )
}

function QuickLinks() {
  const links = [
    {
      to: paths.today,
      label: 'Today',
      text: 'Follow-ups and payments due now',
      icon: CalendarCheckIcon,
    },
    {
      to: paths.approvals,
      label: 'Approvals',
      text: 'Review or track change requests',
      icon: ClipboardCheckIcon,
      anyOf: VIEW_ANY.approvals,
    },
    {
      to: paths.leads,
      label: 'Leads',
      text: 'Work your pipeline',
      icon: TargetIcon,
      anyOf: VIEW_ANY.leads,
    },
  ] as const

  return (
    <ChartCard title="Quick links" description="Jump to the places you use most">
      <ul className="space-y-1">
        {links.map(({ to, label, text, icon: Icon, ...rest }) => {
          const item = (
            <li key={to}>
              <Link
                to={to}
                className="hover:bg-muted/60 -mx-2 flex items-center gap-3 rounded-md px-2 py-2.5"
              >
                <span className="bg-muted text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                  <Icon className="size-4" aria-hidden="true" />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block font-medium">{label}</span>
                  <span className="text-muted-foreground block text-xs">{text}</span>
                </span>
                <ArrowRightIcon className="text-muted-foreground size-4" aria-hidden="true" />
              </Link>
            </li>
          )
          return 'anyOf' in rest ? (
            <Can key={to} anyOf={rest.anyOf}>
              {item}
            </Can>
          ) : (
            item
          )
        })}
      </ul>
    </ChartCard>
  )
}

export default function DashboardPage() {
  const { user, can } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()

  const presetParam = searchParams.get('range')
  const preset: RangePreset = isRangePreset(presetParam) ? presetParam : DEFAULT_PRESET
  const canFilterTeam = can('reports.view-all')
  const teamParam = canFilterTeam ? Number(searchParams.get('team')) || null : null

  const range = useMemo(() => presetRange(preset), [preset])
  const overview = useOverview({ ...range, teamId: teamParam })
  const teams = useTeamFilterOptions({ enabled: canFilterTeam })

  if (!user) return null
  const firstName = user.name.split(' ')[0]
  const comparedTo = `previous ${presetInfo(preset).label.toLowerCase()}`
  const data = overview.data

  function update(key: 'range' | 'team', value: string | null) {
    setSearchParams(
      (current) => {
        const next = new URLSearchParams(current)
        if (value === null) next.delete(key)
        else next.set(key, value)
        return next
      },
      { replace: true },
    )
  }

  const filters = (
    <>
      {canFilterTeam ? (
        <Select
          value={teamParam ? String(teamParam) : ALL_TEAMS}
          onValueChange={(value) => update('team', value === ALL_TEAMS ? null : value)}
        >
          <SelectTrigger size="sm" className="w-44" aria-label="Team">
            <SelectValue />
          </SelectTrigger>
          <SelectContent align="end">
            <SelectItem value={ALL_TEAMS}>All teams</SelectItem>
            {(teams.data ?? []).map((team) => (
              <SelectItem key={team.value} value={team.value}>
                {team.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      ) : null}
      <ToggleGroup
        type="single"
        variant="outline"
        size="sm"
        spacing={0}
        value={preset}
        onValueChange={(value) => {
          if (isRangePreset(value)) update('range', value === DEFAULT_PRESET ? null : value)
        }}
        aria-label="Date range"
      >
        {RANGE_PRESETS.map((item) => (
          <ToggleGroupItem key={item.value} value={item.value} aria-label={`Last ${item.label}`}>
            {item.value}
          </ToggleGroupItem>
        ))}
      </ToggleGroup>
    </>
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title={`${greeting()}, ${firstName}`}
        description={
          <>
            Signed in as {user.roles.map(roleLabel).join(', ')}
            {user.team ? ` on ${user.team.name}` : ''}
            {user.last_login_at ? ` · last sign-in ${formatRelative(user.last_login_at)}` : ''}
          </>
        }
        actions={filters}
      />

      {overview.isError && !data ? (
        <Card>
          <ErrorState
            title="Could not load the dashboard"
            error={overview.error}
            onRetry={() => void overview.refetch()}
          />
        </Card>
      ) : !data ? (
        <div className="space-y-6" aria-busy="true">
          <KpiCardsSkeleton />
          <div className="grid gap-4 lg:grid-cols-3">
            <div className="lg:col-span-2">
              <ChartSkeleton />
            </div>
            <ChartSkeleton />
          </div>
        </div>
      ) : (
        <div
          className={cn('space-y-6 transition-opacity', overview.isPlaceholderData && 'opacity-60')}
          aria-busy={overview.isFetching}
        >
          {overview.isError ? (
            <Card>
              <ErrorState
                title="Could not refresh the dashboard"
                error={overview.error}
                onRetry={() => void overview.refetch()}
              />
            </Card>
          ) : null}
          <KpiCards kpis={data.kpis} comparedTo={comparedTo} />
          <div className="grid gap-4 lg:grid-cols-3">
            <div className="min-w-0 lg:col-span-2">
              <RevenueChart
                series={data.revenue_series}
                bucket={data.range.bucket}
                currency={data.currency}
              />
            </div>
            <FunnelChart funnel={data.funnel} />
          </div>
          {data.leaderboard || data.account_health ? (
            <div className="grid gap-4 lg:grid-cols-3">
              {data.leaderboard ? (
                <div className="min-w-0 lg:col-span-2">
                  <Leaderboard rows={data.leaderboard} />
                </div>
              ) : null}
              {data.account_health ? <AccountHealthChart health={data.account_health} /> : null}
            </div>
          ) : null}
          <div className="grid gap-4 lg:grid-cols-3">
            <div className="min-w-0 lg:col-span-2">
              <UpcomingPayments payments={data.upcoming_payments} />
            </div>
            <QuickLinks />
          </div>
        </div>
      )}
    </div>
  )
}
