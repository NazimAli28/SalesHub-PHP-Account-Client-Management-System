import { TrophyIcon } from 'lucide-react'
import { MoneyText } from '@/components/data-display/MoneyText'
import { EmptyState } from '@/components/layout/EmptyState'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import type { LeaderboardRow } from '../types'
import { ChartCard } from './ChartCard'

export function Leaderboard({ rows }: { rows: LeaderboardRow[] }) {
  const max = Math.max(1, ...rows.map((row) => row.collected.amount_cents ?? 0))

  return (
    <ChartCard title="Top agents" description="By revenue collected in this period">
      {rows.length === 0 ? (
        <EmptyState
          icon={TrophyIcon}
          title="No results yet"
          description="Agents appear once they collect a payment or win a lead."
        />
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-10">#</TableHead>
              <TableHead>Agent</TableHead>
              <TableHead className="text-right">Won</TableHead>
              <TableHead className="text-right">Collected</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((row, index) => (
              <TableRow key={row.user.id}>
                <TableCell className="text-muted-foreground tabular">{index + 1}</TableCell>
                <TableCell>
                  <div className="font-medium">{row.user.name}</div>
                  <div
                    className="bg-muted mt-1 h-1.5 w-full max-w-40 overflow-hidden rounded-full"
                    aria-hidden="true"
                  >
                    <div
                      className="bg-chart-1 h-full rounded-full"
                      style={{ width: `${((row.collected.amount_cents ?? 0) / max) * 100}%` }}
                    />
                  </div>
                </TableCell>
                <TableCell className="tabular text-right">{row.won_leads}</TableCell>
                <TableCell className="text-right">
                  <MoneyText money={row.collected} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </ChartCard>
  )
}
