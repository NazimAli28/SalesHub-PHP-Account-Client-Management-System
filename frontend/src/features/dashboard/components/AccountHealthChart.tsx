import { Cell, Pie, PieChart } from 'recharts'
import { EmptyState } from '@/components/layout/EmptyState'
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from '@/components/ui/chart'
import type { AccountHealthRow } from '../types'
import { ChartCard } from './ChartCard'

/** Standing -> theme color (tokens from index.css so dark mode follows). */
const COLORS: Record<string, string> = {
  active: 'var(--chart-2)',
  limited: 'var(--chart-3)',
  spam: 'var(--chart-4)',
  violation: 'var(--destructive)',
  disabled: 'var(--chart-5)',
}

function colorFor(standing: string): string {
  return COLORS[standing] ?? 'var(--chart-5)'
}

export function AccountHealthChart({ health }: { health: AccountHealthRow[] }) {
  const total = health.reduce((sum, row) => sum + row.count, 0)
  const config: ChartConfig = Object.fromEntries(
    health.map((row) => [
      row.standing.value,
      { label: row.standing.label, color: colorFor(row.standing.value) },
    ]),
  )
  const slices = health.filter((row) => row.count > 0)
  const summary = `Platform account standing: ${health
    .map((row) => `${row.standing.label} ${row.count}`)
    .join(', ')}. ${total} accounts in total.`

  return (
    <ChartCard title="Account health" description="Platform accounts by standing">
      {total === 0 ? (
        <EmptyState
          title="No platform accounts"
          description="Accounts you can see will show here."
        />
      ) : (
        <div className="flex flex-col items-center gap-4 sm:flex-row">
          <div role="img" aria-label={summary} className="relative shrink-0">
            <ChartContainer config={config} className="mx-auto aspect-square h-44 w-44">
              <PieChart>
                <ChartTooltip content={<ChartTooltipContent hideLabel nameKey="standing" />} />
                <Pie
                  data={slices.map((row) => ({ standing: row.standing.value, count: row.count }))}
                  dataKey="count"
                  nameKey="standing"
                  innerRadius={52}
                  outerRadius={80}
                  strokeWidth={2}
                  isAnimationActive={false}
                >
                  {slices.map((row) => (
                    <Cell key={row.standing.value} fill={`var(--color-${row.standing.value})`} />
                  ))}
                </Pie>
              </PieChart>
            </ChartContainer>
            <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
              <span className="tabular text-2xl font-semibold">{total}</span>
              <span className="text-muted-foreground text-xs">accounts</span>
            </div>
          </div>
          <ul className="w-full space-y-2 text-sm" aria-label="Accounts by standing">
            {health.map((row) => (
              <li key={row.standing.value} className="flex items-center justify-between gap-3">
                <span className="flex items-center gap-2">
                  <span
                    className="size-2.5 rounded-full"
                    style={{ background: colorFor(row.standing.value) }}
                    aria-hidden="true"
                  />
                  {row.standing.label}
                </span>
                <span className="tabular font-medium">{row.count}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </ChartCard>
  )
}
