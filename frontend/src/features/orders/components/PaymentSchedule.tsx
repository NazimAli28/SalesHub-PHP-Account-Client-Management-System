import { useState } from 'react'
import { PlusIcon, WalletIcon } from 'lucide-react'
import { MoneyText } from '@/components/data-display/MoneyText'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { StatusBadge } from '@/components/data-display/StatusBadge'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Can } from '@/features/auth/Can'
import { OverdueFlag } from '@/features/payments/components/OverdueFlag'
import { PaymentFormDialog } from '@/features/payments/components/PaymentFormDialog'
import { PaymentRowActions } from '@/features/payments/components/PaymentRowActions'
import { cn } from '@/lib/utils'
import { unscheduledCents } from '../schemas'
import type { Order } from '../types'

/** The order's installments: add, mark paid, edit, delete. Overdue rows are highlighted. */
export function PaymentSchedule({ order }: { order: Order }) {
  const [adding, setAdding] = useState(false)
  const payments = order.payments ?? []
  const remaining = unscheduledCents(order)

  return (
    <Card>
      <CardHeader>
        <CardTitle>Payment schedule</CardTitle>
        <CardDescription>
          {remaining > 0 ? (
            <>
              <MoneyText cents={remaining} currency={order.currency} /> of the total is not
              scheduled yet.
            </>
          ) : (
            'The whole order total is scheduled.'
          )}
        </CardDescription>
        <Can permission="payments.create">
          <CardAction>
            <Button size="sm" onClick={() => setAdding(true)}>
              <PlusIcon aria-hidden="true" />
              Add installment
            </Button>
          </CardAction>
        </Can>
      </CardHeader>
      <CardContent>
        {payments.length === 0 ? (
          <p className="text-muted-foreground flex items-center gap-2 py-6 text-sm">
            <WalletIcon className="size-4" aria-hidden="true" />
            No installments scheduled yet.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <Table aria-label="Payment schedule">
              <TableHeader>
                <TableRow>
                  <TableHead className="w-10">#</TableHead>
                  <TableHead>Due</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Paid</TableHead>
                  <TableHead>Method</TableHead>
                  <TableHead className="w-12" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {payments.map((payment) => (
                  <TableRow
                    key={payment.id}
                    data-overdue={payment.is_overdue || undefined}
                    className={cn(payment.is_overdue && 'bg-destructive/5 hover:bg-destructive/10')}
                  >
                    <TableCell className="tabular">{payment.sequence}</TableCell>
                    <TableCell className={cn(payment.is_overdue && 'text-destructive font-medium')}>
                      <RelativeTime value={payment.due_date} display="date" />
                    </TableCell>
                    <TableCell className="text-right">
                      <MoneyText money={payment.amount} />
                    </TableCell>
                    <TableCell>
                      <div className="flex flex-wrap items-center gap-1.5">
                        <StatusBadge kind="paymentStatus" value={payment.status} />
                        {payment.is_overdue ? <OverdueFlag /> : null}
                      </div>
                    </TableCell>
                    <TableCell>
                      <RelativeTime value={payment.paid_at} display="date" />
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      {payment.method?.label ?? '—'}
                    </TableCell>
                    <TableCell className="text-right">
                      <PaymentRowActions payment={payment} showOrderLink={false} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </CardContent>

      <PaymentFormDialog
        open={adding}
        onOpenChange={setAdding}
        orderId={order.id}
        suggestedCents={remaining > 0 ? remaining : null}
        currency={order.currency}
      />
    </Card>
  )
}
