import { useMemo, useState } from 'react'
import { Link } from 'react-router'
import { detailPath, paths } from '@/app/paths'
import type { Lead } from '@/api/types'
import type { ListParams } from '@/api/list-params'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { useOrders } from '@/features/orders/api'
import { formatDate } from '@/lib/format'
import { leadName } from './board-api'

interface WonOrderDialogProps {
  lead: Lead | null
  onCancel: () => void
  onConfirm: (lead: Lead, orderId: number) => void
}

export function WonOrderDialog({ lead, onCancel, onConfirm }: WonOrderDialogProps) {
  return (
    <Dialog open={lead !== null} onOpenChange={(open) => (open ? undefined : onCancel())}>
      <DialogContent>
        {lead ? (
          <WonOrderForm key={lead.id} lead={lead} onCancel={onCancel} onConfirm={onConfirm} />
        ) : null}
      </DialogContent>
    </Dialog>
  )
}

function WonOrderForm({
  lead,
  onCancel,
  onConfirm,
}: {
  lead: Lead
  onCancel: () => void
  onConfirm: WonOrderDialogProps['onConfirm']
}) {
  const [orderId, setOrderId] = useState<number | null>(null)
  const [touched, setTouched] = useState(false)
  const params = useMemo<ListParams>(
    () => ({
      page: 1,
      pageSize: 50,
      sort: '-ordered_on',
      search: '',
      filters: { client: [String(lead.client_id)] },
    }),
    [lead.client_id],
  )
  const orders = useOrders(params)
  const rows = orders.data?.data ?? []

  return (
    <form
      className="space-y-4"
      onSubmit={(event) => {
        event.preventDefault()
        setTouched(true)
        if (orderId !== null) onConfirm(lead, orderId)
      }}
    >
      <DialogHeader>
        <DialogTitle>Mark as won</DialogTitle>
        <DialogDescription>
          Choose the order that closed the lead for {leadName(lead)}.
        </DialogDescription>
      </DialogHeader>

      {orders.isPending ? (
        <Skeleton className="h-20 w-full" />
      ) : orders.isError ? (
        <p role="alert" className="text-destructive text-sm">
          Could not load this client&apos;s orders.
        </p>
      ) : rows.length === 0 ? (
        <div className="bg-muted/50 space-y-2 rounded-lg border p-3 text-sm">
          <p className="font-medium">No orders for this client yet</p>
          <p className="text-muted-foreground">
            A lead can only be won once an order exists for the same client. Create the order first,
            then move the lead again.
          </p>
          <div className="flex gap-3">
            <Link className="underline" to={paths.orders}>
              Go to orders
            </Link>
            <Link className="underline" to={detailPath.client(lead.client_id)}>
              Open client
            </Link>
          </div>
        </div>
      ) : (
        <fieldset className="space-y-2">
          <legend className="sr-only">Order</legend>
          {rows.map((order) => (
            <label
              key={order.id}
              className="has-[:checked]:border-primary flex cursor-pointer items-center gap-3 rounded-lg border p-2.5 text-sm"
            >
              <input
                type="radio"
                name="won-order"
                checked={orderId === order.id}
                onChange={() => setOrderId(order.id)}
              />
              <span className="font-medium">{order.order_number}</span>
              <span className="tabular-nums">{order.total?.formatted}</span>
              <span className="text-muted-foreground ml-auto">{formatDate(order.ordered_on)}</span>
            </label>
          ))}
          {touched && orderId === null ? (
            <p role="alert" className="text-destructive text-sm">
              Choose an order.
            </p>
          ) : null}
        </fieldset>
      )}

      <DialogFooter>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" disabled={rows.length === 0}>
          Mark as won
        </Button>
      </DialogFooter>
    </form>
  )
}
