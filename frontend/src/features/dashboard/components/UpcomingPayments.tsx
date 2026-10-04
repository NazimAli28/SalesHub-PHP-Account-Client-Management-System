import { CalendarClockIcon } from 'lucide-react'
import { Link } from 'react-router'
import { detailPath } from '@/app/paths'
import { MoneyText } from '@/components/data-display/MoneyText'
import { EmptyState } from '@/components/layout/EmptyState'
import { formatDate } from '@/lib/format'
import type { UpcomingPayment } from '../types'
import { ChartCard } from './ChartCard'

export function UpcomingPayments({ payments }: { payments: UpcomingPayment[] }) {
  return (
    <ChartCard title="Upcoming payments" description="Next scheduled payments to chase">
      {payments.length === 0 ? (
        <EmptyState
          icon={CalendarClockIcon}
          title="No upcoming payments"
          description="Scheduled payments with a future due date will show here."
        />
      ) : (
        <ul className="divide-y">
          {payments.map((payment) => (
            <li key={payment.id}>
              <Link
                to={detailPath.order(payment.order.id)}
                className="hover:bg-muted/60 -mx-2 flex items-center justify-between gap-3 rounded-md px-2 py-2.5"
              >
                <span className="min-w-0">
                  <span className="block truncate font-medium">
                    {payment.order.client_name ?? 'Client'}
                  </span>
                  <span className="text-muted-foreground block truncate text-xs">
                    {payment.order.order_number ?? `Order ${payment.order.id}`} · due{' '}
                    {formatDate(payment.due_date)}
                  </span>
                </span>
                <MoneyText money={payment.amount} className="font-medium" />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </ChartCard>
  )
}
