import { Bar, BarChart, CartesianGrid, LabelList, XAxis, YAxis } from 'recharts'
import { EmptyState } from '@/components/layout/EmptyState'
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from '@/components/ui/chart'
import type { FunnelStage } from '../types'
import { ChartCard } from './ChartCard'

const config = { count: { label: 'Leads', color: 'var(--chart-1)' } } satisfies ChartConfig

export function FunnelChart({ funnel }: { funnel: FunnelStage[] }) {
  const total = funnel.reduce((sum, row) => sum + row.count, 0)
  const data = funnel.map((row) => ({ stage: row.stage.label, count: row.count }))
  const summary = `Lead funnel for leads created in this period: ${funnel
    .map((row) => `${row.stage.label} ${row.count}`)
    .join(', ')}.`

  return (
    <ChartCard title="Lead funnel" description="Leads created in this period, by current stage">
      {total === 0 ? (
        <EmptyState
          title="No new leads in this period"
          description="Add leads to see how they move through the pipeline."
        />
      ) : (
        <>
          <div role="img" aria-label={summary}>
            <ChartContainer config={config} className="aspect-auto h-64 w-full">
              <BarChart data={data} layout="vertical" margin={{ left: 0, right: 28 }}>
                <CartesianGrid horizontal={false} />
                <YAxis
                  dataKey="stage"
                  type="category"
                  width={110}
                  tickLine={false}
                  axisLine={false}
                />
                <XAxis type="number" hide allowDecimals={false} />
                <ChartTooltip cursor={false} content={<ChartTooltipContent hideLabel />} />
                <Bar dataKey="count" fill="var(--color-count)" radius={4}>
                  <LabelList dataKey="count" position="right" className="fill-foreground" />
                </Bar>
              </BarChart>
            </ChartContainer>
          </div>
          <table className="sr-only">
            <caption>Lead funnel</caption>
            <thead>
              <tr>
                <th>Stage</th>
                <th>Leads</th>
              </tr>
            </thead>
            <tbody>
              {funnel.map((row) => (
                <tr key={row.stage.value}>
                  <td>{row.stage.label}</td>
                  <td>{row.count}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}
    </ChartCard>
  )
}
