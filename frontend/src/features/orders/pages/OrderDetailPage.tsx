import { useState } from 'react'
import { ClockIcon, PencilIcon, Trash2Icon } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router'
import { isApiError } from '@/api/errors'
import { detailPath, paths } from '@/app/paths'
import { MoneyText } from '@/components/data-display/MoneyText'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { StatusBadge } from '@/components/data-display/StatusBadge'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { ErrorState } from '@/components/layout/ErrorState'
import { CardSkeleton } from '@/components/layout/LoadingSkeleton'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { useAuth } from '@/features/auth/AuthProvider'
import { KpiCard } from '@/features/clients/components/KpiCard'
import { clientDisplayName } from '@/features/clients/types'
import { formatRelative } from '@/lib/format'
import { useDeleteOrder, useOrder } from '../api'
import { OrderEditDialog } from '../components/OrderEditDialog'
import { OrderItemsEditor } from '../components/OrderItemsEditor'
import { PaymentSchedule } from '../components/PaymentSchedule'

export default function OrderDetailPage() {
  const { orderId } = useParams()
  const id = Number(orderId)
  const orderQuery = useOrder(id)
  const navigate = useNavigate()
  const { can, canAny } = useAuth()
  const [editing, setEditing] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const remove = useDeleteOrder()

  if (orderQuery.isPending) {
    return (
      <div className="space-y-4" role="status" aria-label="Loading order">
        <CardSkeleton />
        <CardSkeleton />
      </div>
    )
  }
  if (orderQuery.isError) {
    const error = orderQuery.error
    const denied = isApiError(error) && (error.isForbidden || error.isNotFound)
    return (
      <ErrorState
        title={denied ? "You don't have access to this record" : 'Could not load this order'}
        error={denied ? undefined : error}
        onRetry={denied ? undefined : () => void orderQuery.refetch()}
      />
    )
  }

  const order = orderQuery.data
  const pending = order.pending_change
  const canEdit = canAny(['orders.update', 'orders.request-change'])
  const canDelete = canAny(['orders.delete', 'orders.request-change'])
  const deleteNeedsApproval = !can('orders.delete')

  return (
    <div className="space-y-6">
      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-3">
            {order.order_number}
            <StatusBadge kind="orderStatus" value={order.status} />
          </span>
        }
        description={
          <>
            {order.client ? (
              <Link
                to={detailPath.client(order.client.id)}
                className="text-foreground font-medium hover:underline"
              >
                {clientDisplayName(order.client)}
              </Link>
            ) : (
              `Client #${order.client_id}`
            )}
            {' · ordered '}
            <RelativeTime value={order.ordered_on} display="date" />
            {order.owner ? ` · owned by ${order.owner.name}` : null}
          </>
        }
        actions={
          <>
            {canEdit ? (
              <Button
                variant="outline"
                disabled={pending !== null}
                onClick={() => setEditing(true)}
              >
                <PencilIcon aria-hidden="true" />
                {can('orders.update') ? 'Edit' : 'Request change'}
              </Button>
            ) : null}
            {canDelete ? (
              <Button
                variant="outline"
                disabled={pending !== null}
                onClick={() => setConfirmDelete(true)}
              >
                <Trash2Icon aria-hidden="true" />
                {deleteNeedsApproval ? 'Request deletion' : 'Delete'}
              </Button>
            ) : null}
          </>
        }
      />

      {pending ? (
        <Card className="border-amber-500/40 bg-amber-500/5">
          <CardContent className="flex items-center gap-2 text-sm">
            <ClockIcon className="size-4 text-amber-600" aria-hidden="true" />
            {pending.action.label} pending approval · requested by {pending.requested_by.name}{' '}
            {formatRelative(pending.requested_at)}. Edits are locked until it is decided.
          </CardContent>
        </Card>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard label="Total" value={<MoneyText money={order.total} />} />
        <KpiCard label="Paid" value={<MoneyText money={order.amount_paid} />} />
        <KpiCard label="Balance" value={<MoneyText money={order.balance} />} />
        <KpiCard
          label="Overdue installments"
          value={order.overdue_payments_count}
          tone={order.overdue_payments_count > 0 ? 'danger' : 'default'}
        />
      </div>

      <OrderItemsEditor order={order} />
      <PaymentSchedule order={order} />

      {order.notes ? (
        <Card>
          <CardContent className="space-y-1">
            <h2 className="text-sm font-medium">Notes</h2>
            <p className="text-muted-foreground text-sm whitespace-pre-wrap">{order.notes}</p>
          </CardContent>
        </Card>
      ) : null}

      <OrderEditDialog open={editing} onOpenChange={setEditing} order={order} />
      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={deleteNeedsApproval ? 'Request deletion of this order?' : 'Delete this order?'}
        description={
          deleteNeedsApproval
            ? `A reviewer must approve deleting ${order.order_number} before it is removed.`
            : `${order.order_number} and its payment schedule will be removed. This cannot be undone here.`
        }
        confirmLabel={deleteNeedsApproval ? 'Send request' : 'Delete order'}
        destructive
        pending={remove.isPending}
        onConfirm={async () => {
          // A 202 keeps the order (it waits for approval); only a real delete leaves the page.
          const result = await remove.mutateAsync(order.id)
          if (result.kind === 'applied') void navigate(paths.orders)
        }}
      />
    </div>
  )
}
