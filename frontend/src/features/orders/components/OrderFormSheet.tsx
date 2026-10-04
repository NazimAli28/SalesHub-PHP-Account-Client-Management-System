import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { PlusIcon, Trash2Icon } from 'lucide-react'
import { useFieldArray, useForm, useWatch } from 'react-hook-form'
import { MoneyText } from '@/components/data-display/MoneyText'
import {
  AsyncComboboxField,
  DateField,
  FormSheet,
  MoneyField,
  TextareaField,
} from '@/components/form'
import { Button } from '@/components/ui/button'
import { fetchClientOptions } from '@/features/clients/api'
import { useCreateOrder } from '../api'
import {
  computeOrderTotals,
  emptyItem,
  lineTotalCents,
  orderFormDefaults,
  orderFormSchema,
  toOrderPayload,
  type OrderFormValues,
} from '../schemas'
import type { Order } from '../types'
import { ItemFields } from './ItemFields'

interface OrderFormSheetProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Pre-selects the client (e.g. when opened from a Client 360). */
  client?: { id: number; label: string } | null
  onCreated?: (order: Order) => void
}

/** "New order": client, line items with a live total, discount and notes. */
export function OrderFormSheet({
  open,
  onOpenChange,
  client = null,
  onCreated,
}: OrderFormSheetProps) {
  const [today] = useState(() => new Date())
  const form = useForm<OrderFormValues>({
    resolver: zodResolver(orderFormSchema),
    defaultValues: orderFormDefaults(client?.id ?? null),
  })
  const items = useFieldArray({ control: form.control, name: 'items' })
  const [watchedItems, discount] = useWatch({
    control: form.control,
    name: ['items', 'discount_cents'],
  })
  const totals = computeOrderTotals(watchedItems ?? [], discount ?? null)

  useEffect(() => {
    if (open) form.reset(orderFormDefaults(client?.id ?? null))
  }, [open, client, form])

  const create = useCreateOrder({
    form,
    onSuccess: (order) => {
      onOpenChange(false)
      onCreated?.(order)
    },
  })

  return (
    <FormSheet
      open={open}
      onOpenChange={onOpenChange}
      title="New order"
      description="Pick the client and add what they are buying. You can schedule payments afterwards."
      form={form}
      onSubmit={(values) => create.mutate(toOrderPayload(values))}
      pending={create.isPending}
      submitLabel="Create order"
    >
      <AsyncComboboxField
        control={form.control}
        name="client_id"
        label="Client"
        required
        queryKey={['clients', 'options']}
        fetchOptions={fetchClientOptions}
        initialOption={client ? { value: client.id, label: client.label } : null}
        placeholder="Search clients…"
        searchPlaceholder="Name, email or Discord username"
      />

      <fieldset className="space-y-3">
        <legend className="mb-1 text-sm font-medium">Items</legend>
        {items.fields.map((item, index) => (
          <div key={item.id} className="bg-muted/30 space-y-3 rounded-lg border p-3">
            <ItemFields form={form} prefix={`items.${index}.`} />
            <div className="flex items-center justify-between gap-2">
              <span className="text-muted-foreground text-sm">
                Line total{' '}
                <MoneyText
                  className="text-foreground font-medium"
                  cents={lineTotalCents(watchedItems?.[index] ?? emptyItem())}
                />
              </span>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                disabled={items.fields.length === 1}
                onClick={() => items.remove(index)}
                aria-label={`Remove item ${index + 1}`}
              >
                <Trash2Icon aria-hidden="true" />
                Remove
              </Button>
            </div>
          </div>
        ))}
        {form.formState.errors.items?.message ? (
          <p role="alert" className="text-destructive text-sm">
            {form.formState.errors.items.message}
          </p>
        ) : null}
        <Button type="button" variant="outline" size="sm" onClick={() => items.append(emptyItem())}>
          <PlusIcon aria-hidden="true" />
          Add item
        </Button>
      </fieldset>

      <div className="grid gap-5 sm:grid-cols-2">
        <MoneyField control={form.control} name="discount_cents" label="Discount" />
        <DateField
          control={form.control}
          name="ordered_on"
          label="Ordered on"
          maxDate={today}
          clearable={false}
        />
      </div>

      <dl
        data-testid="order-totals"
        className="bg-muted/40 space-y-1 rounded-lg border px-4 py-3 text-sm"
      >
        <div className="flex justify-between">
          <dt className="text-muted-foreground">Subtotal</dt>
          <dd data-testid="order-subtotal">
            <MoneyText cents={totals.subtotal} />
          </dd>
        </div>
        <div className="flex justify-between">
          <dt className="text-muted-foreground">Discount</dt>
          <dd>
            <MoneyText cents={-totals.discount} />
          </dd>
        </div>
        <div className="flex justify-between border-t pt-1 text-base font-semibold">
          <dt>Total</dt>
          <dd data-testid="order-total">
            <MoneyText cents={totals.total} />
          </dd>
        </div>
      </dl>

      <TextareaField control={form.control} name="notes" label="Notes" rows={2} maxLength={5000} />
    </FormSheet>
  )
}
