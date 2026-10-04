import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { FormDialog, TextField } from '@/components/form'
import { pickChanged } from '@/features/clients/schemas'
import { useAddOrderItem, useUpdateOrderItem } from '../api'
import { itemFormDefaults, itemFormSchema, toItemPayload, type ItemFormValues } from '../schemas'
import type { OrderItem } from '../types'
import { ItemFields } from './ItemFields'

interface OrderItemDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  orderId: number
  currency: string
  /** Edit this item; omit to add a new one. */
  item?: OrderItem | null
}

/** Add or edit one line item through the nested `/orders/{id}/items` endpoints. */
export function OrderItemDialog({
  open,
  onOpenChange,
  orderId,
  currency,
  item,
}: OrderItemDialogProps) {
  const form = useForm<ItemFormValues>({
    resolver: zodResolver(itemFormSchema),
    defaultValues: itemFormDefaults(item),
  })

  useEffect(() => {
    if (open) form.reset(itemFormDefaults(item))
  }, [open, item, form])

  const close = () => onOpenChange(false)
  const add = useAddOrderItem(orderId, { form, onSuccess: close })
  const update = useUpdateOrderItem(orderId, { form, onSuccess: close })

  const onSubmit = (values: ItemFormValues) => {
    const payload = toItemPayload(values)
    if (item) {
      update.mutate({
        itemId: item.id,
        payload: pickChanged(payload, form.formState.dirtyFields),
      })
    } else {
      add.mutate(payload)
    }
  }

  return (
    <FormDialog
      open={open}
      onOpenChange={onOpenChange}
      title={item ? 'Edit item' : 'Add item'}
      description="The order total is recalculated when you save."
      form={form}
      onSubmit={onSubmit}
      pending={add.isPending || update.isPending}
      submitLabel={item ? 'Save item' : 'Add item'}
    >
      <ItemFields
        form={form}
        prefix=""
        currency={currency}
        initialService={item?.service ? { value: item.service.id, label: item.service.name } : null}
      />
      <TextField
        control={form.control}
        name="description"
        label="Description"
        description="Optional detail shown on the order line."
        maxLength={255}
      />
    </FormDialog>
  )
}
