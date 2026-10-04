import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { FormDialog, MoneyField, SelectField, TextareaField } from '@/components/form'
import { pickChanged } from '@/features/clients/schemas'
import { useAuth } from '@/features/auth/AuthProvider'
import { orderStatuses } from '@/lib/enums'
import { useUpdateOrder } from '../api'
import {
  orderEditDefaults,
  orderEditSchema,
  toOrderEditPayload,
  type OrderEditValues,
} from '../schemas'
import type { Order } from '../types'

interface OrderEditDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  order: Order
}

/** Edit status, discount and notes. Without `orders.update` the change is sent for approval (202). */
export function OrderEditDialog({ open, onOpenChange, order }: OrderEditDialogProps) {
  const { can } = useAuth()
  const needsApproval = !can('orders.update')

  const form = useForm<OrderEditValues>({
    resolver: zodResolver(orderEditSchema),
    defaultValues: orderEditDefaults(order),
  })

  useEffect(() => {
    if (open) form.reset(orderEditDefaults(order))
  }, [open, order, form])

  const update = useUpdateOrder({ form, onSuccess: () => onOpenChange(false) })

  return (
    <FormDialog
      open={open}
      onOpenChange={onOpenChange}
      title={`Edit ${order.order_number}`}
      description={
        needsApproval
          ? 'Your changes will be sent to a reviewer before they apply.'
          : 'Change the status, discount or notes. Items are edited on the order itself.'
      }
      form={form}
      onSubmit={(values) =>
        update.mutate({
          id: order.id,
          payload: pickChanged(toOrderEditPayload(values), form.formState.dirtyFields),
        })
      }
      pending={update.isPending}
      submitLabel={needsApproval ? 'Send for approval' : 'Save changes'}
    >
      <SelectField
        control={form.control}
        name="status"
        label="Status"
        required
        options={orderStatuses.options}
      />
      <MoneyField
        control={form.control}
        name="discount_cents"
        label="Discount"
        currency={order.currency}
      />
      <TextareaField control={form.control} name="notes" label="Notes" rows={3} maxLength={5000} />
      {needsApproval ? (
        <TextareaField
          control={form.control}
          name="reason"
          label="Reason for the change"
          rows={2}
          maxLength={500}
        />
      ) : null}
    </FormDialog>
  )
}
