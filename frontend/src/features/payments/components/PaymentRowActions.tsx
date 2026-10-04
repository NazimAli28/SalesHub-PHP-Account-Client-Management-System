import { useState } from 'react'
import { CheckCircle2Icon, ExternalLinkIcon, PencilIcon, Trash2Icon } from 'lucide-react'
import { Link } from 'react-router'
import { detailPath } from '@/app/paths'
import { DataTableRowActions } from '@/components/data-table'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDeletePayment } from '../api'
import type { Payment } from '../types'
import { MarkPaidDialog } from './MarkPaidDialog'
import { PaymentFormDialog } from './PaymentFormDialog'

interface PaymentRowActionsProps {
  payment: Payment
  /** Link to the order (hide it on the order's own page). */
  showOrderLink?: boolean
}

/**
 * Row menu for one installment: mark paid, edit, delete. Users with only `payments.request-change`
 * see the same items; the API queues their changes (202).
 */
export function PaymentRowActions({ payment, showOrderLink = true }: PaymentRowActionsProps) {
  const { can, canAny } = useAuth()
  const [dialog, setDialog] = useState<'paid' | 'edit' | 'delete' | null>(null)
  const remove = useDeletePayment()

  const isScheduled = payment.status.value === 'scheduled'
  const canChange = canAny(['payments.update', 'payments.request-change'])
  // Editing a paid payment directly is limited to support/admin; the API queues it for others.
  const canEdit = canChange
  const canMarkPaid = canChange && isScheduled
  const canDelete = canAny(['payments.delete', 'payments.request-change'])
  const deleteNeedsApproval = !can('payments.delete')
  const hasPendingChange = payment.pending_change !== null
  const label = `installment ${payment.sequence}${payment.order ? ` of ${payment.order.order_number}` : ''}`

  return (
    <>
      <DataTableRowActions label={`Actions for ${label}`}>
        {canMarkPaid ? (
          <DropdownMenuItem disabled={hasPendingChange} onSelect={() => setDialog('paid')}>
            <CheckCircle2Icon aria-hidden="true" />
            Mark paid
          </DropdownMenuItem>
        ) : null}
        {canEdit ? (
          <DropdownMenuItem disabled={hasPendingChange} onSelect={() => setDialog('edit')}>
            <PencilIcon aria-hidden="true" />
            Edit
          </DropdownMenuItem>
        ) : null}
        {showOrderLink ? (
          <DropdownMenuItem asChild>
            <Link to={detailPath.order(payment.order_id)}>
              <ExternalLinkIcon aria-hidden="true" />
              Open order
            </Link>
          </DropdownMenuItem>
        ) : null}
        {canDelete ? (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              variant="destructive"
              disabled={hasPendingChange}
              onSelect={() => setDialog('delete')}
            >
              <Trash2Icon aria-hidden="true" />
              {deleteNeedsApproval ? 'Request deletion' : 'Delete'}
            </DropdownMenuItem>
          </>
        ) : null}
      </DataTableRowActions>

      <MarkPaidDialog
        open={dialog === 'paid'}
        onOpenChange={(open) => !open && setDialog(null)}
        payment={payment}
      />
      <PaymentFormDialog
        open={dialog === 'edit'}
        onOpenChange={(open) => !open && setDialog(null)}
        orderId={payment.order_id}
        payment={payment}
        currency={payment.currency}
      />
      <ConfirmDialog
        open={dialog === 'delete'}
        onOpenChange={(open) => !open && setDialog(null)}
        title={
          deleteNeedsApproval ? 'Request deletion of this installment?' : 'Delete this installment?'
        }
        description={
          deleteNeedsApproval
            ? `A reviewer must approve deleting ${label} before it is removed.`
            : `${label} will be removed from the payment schedule. This cannot be undone here.`
        }
        confirmLabel={deleteNeedsApproval ? 'Send request' : 'Delete installment'}
        destructive
        pending={remove.isPending}
        onConfirm={() => remove.mutateAsync({ id: payment.id })}
      />
    </>
  )
}
