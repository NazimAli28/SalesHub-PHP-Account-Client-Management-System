import { useState } from 'react'
import { ExternalLinkIcon, Trash2Icon } from 'lucide-react'
import { Link } from 'react-router'
import { detailPath } from '@/app/paths'
import { DataTableRowActions } from '@/components/data-table'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDeleteOrder } from '../api'
import type { Order } from '../types'

/** Row menu: open and delete (editing happens on the order page). */
export function OrderRowActions({ order }: { order: Order }) {
  const { can, canAny } = useAuth()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const remove = useDeleteOrder()

  const canDelete = canAny(['orders.delete', 'orders.request-change'])
  const deleteNeedsApproval = !can('orders.delete')

  return (
    <>
      <DataTableRowActions label={`Actions for ${order.order_number}`}>
        <DropdownMenuItem asChild>
          <Link to={detailPath.order(order.id)}>
            <ExternalLinkIcon aria-hidden="true" />
            Open order
          </Link>
        </DropdownMenuItem>
        {canDelete ? (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              variant="destructive"
              disabled={order.pending_change !== null}
              onSelect={() => setConfirmDelete(true)}
            >
              <Trash2Icon aria-hidden="true" />
              {deleteNeedsApproval ? 'Request deletion' : 'Delete'}
            </DropdownMenuItem>
          </>
        ) : null}
      </DataTableRowActions>

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
        onConfirm={() => remove.mutateAsync(order.id)}
      />
    </>
  )
}
