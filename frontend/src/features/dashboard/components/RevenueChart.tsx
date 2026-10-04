import { format, parseISO } from 'date-fns'
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts'
import { EmptyState } from '@/components/layout/EmptyState'
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from '@/components/ui/chart'
import { formatCents } from '@/lib/format'
import type { Bucket, RevenuePoint } from '../types'
import { ChartCard } from './ChartCard'

const config = {
  collected_cents: { label: 'Collected', color: 'var(--chart-1)' },
  won_cents: { label: 'Won', color: 'var(--chart-2)' },
} satisfies ChartConfig

const BUCKET_LABEL: Record<Bucket, string> = { day: 'daily', week: 'weekly', month: 'monthly' }

function tickLabel(date: string, bucket: Bucket): string {
  return format(parseISO(date), bucket === 'month' ? 'MMM yy' : 'MMM d')
}

function compactCurrency(cents: number, currency: string): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(cents / 100)
}

interface RevenueChartProps {
  series: RevenuePoint[]
  bucket: Bucket
  currency: string
}

export function RevenueChart({ series, bucket, currency }: RevenueChartProps) {
  const collected = series.reduce((sum, point) => sum + point.collected_cents, 0)
  const won = series.reduce((sum, point) => sum + point.won_cents, 0)
  const empty = collected === 0 && won === 0
  const best = series.reduce<RevenuePoint | null>(
    (top, point) => (!top || point.collected_cents > top.collected_cents ? point : top),
    null,
  )
  const summary = empty
    ? 'No revenue or won deals in this period.'
    : `${BUCKET_LABEL[bucket]} revenue: ${formatCents(collected, currency)} collected and ${formatCents(won, currency)} won across ${series.length} periods. Highest collection starts ${best ? tickLabel(best.date, bucket) : 'n/a'} at ${formatCents(best?.collected_cents ?? 0, currency)}.`

  return (
    <ChartCard
      title="Revenue over time"
      description={`Collected payments and won lead value, ${BUCKET_LABEL[bucket]}`}
    >
      {empty ? (
        <EmptyState
          title="No revenue in this period"
          description="Collected payments and won deals will appear here."
        />
      ) : (
        <>
          <div role="img" aria-label={summary}>
            <ChartContainer config={config} className="aspect-auto h-64 w-full">
              <AreaChart data={series} margin={{ left: 4, right: 8, top: 8 }}>
                <CartesianGrid vertical={false} />
                <XAxis
                  dataKey="date"
                  tickLine={false}
                  axisLine={false}
                  tickMargin={8}
                  minTickGap={24}
                  tickFormatter={(value: string) => tickLabel(value, bucket)}
                />
                <YAxis
                  width={52}
                  tickLine={false}
                  axisLine={false}
                  tickFormatter={(value: number) => compactCurrency(value, currency)}
                />
                <ChartTooltip
                  content={
                    <ChartTooltipContent
                      labelFormatter={(value) =>
                        `${bucket === 'day' ? '' : 'From '}${format(parseISO(String(value)), 'MMM d, yyyy')}`
                      }
                      formatter={(value, name) => (
                        <span className="flex w-full items-center justify-between gap-4">
                          <span className="text-muted-foreground">
                            {config[name as keyof typeof config]?.label ?? name}
                          </span>
                          <span className="font-mono font-medium tabular-nums">
                            {formatCents(Number(value), currency)}
                          </span>
                        </span>
                      )}
                    />
                  }
                />
                <ChartLegend content={<ChartLegendContent />} />
                <Area
                  dataKey="won_cents"
                  type="monotone"
                  stroke="var(--color-won_cents)"
                  fill="var(--color-won_cents)"
                  fillOpacity={0.15}
                  strokeWidth={2}
                />
                <Area
                  dataKey="collected_cents"
                  type="monotone"
                  stroke="var(--color-collected_cents)"
                  fill="var(--color-collected_cents)"
                  fillOpacity={0.25}
                  strokeWidth={2}
                />
              </AreaChart>
            </ChartContainer>
          </div>
          <p className="sr-only">{summary}</p>
        </>
      )}
    </ChartCard>
  )
}
