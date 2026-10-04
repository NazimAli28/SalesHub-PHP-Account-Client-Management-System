import { useState } from 'react'
import { PackageIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react'
import { MoneyText } from '@/components/data-display/MoneyText'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
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
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useAuth } from '@/features/auth/AuthProvider'
import { useRemoveOrderItem } from '../api'
import type { Order, OrderItem } from '../types'
import { OrderItemDialog } from './OrderItemDialog'

/**
 * Line items. Item edits are direct writes (the API has no approval path for them), so the
 * editing controls only show for users with `orders.update`; everyone else sees a read-only table.
 */
export function OrderItemsEditor({ order }: { order: Order }) {
  const { can } = useAuth()
  const canEdit = can('orders.update') && order.pending_change === null
  const [dialog, setDialog] = useState<{ open: boolean; item: OrderItem | null }>({
    open: false,
    item: null,
  })
  const [removing, setRemoving] = useState<OrderItem | null>(null)
  const remove = useRemoveOrderItem(order.id)
  const items = order.items ?? []

  return (
    <Card>
      <CardHeader>
        <CardTitle>Items</CardTitle>
        <CardDescription>What the client is buying.</CardDescription>
        {canEdit ? (
          <CardAction>
            <Button size="sm" onClick={() => setDialog({ open: true, item: null })}>
              <PlusIcon aria-hidden="true" />
              Add item
            </Button>
          </CardAction>
        ) : null}
      </CardHeader>
      <CardContent>
        {items.length === 0 ? (
          <p className="text-muted-foreground flex items-center gap-2 py-6 text-sm">
            <PackageIcon className="size-4" aria-hidden="true" />
            This order has no items.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <Table aria-label="Order items">
              <TableHeader>
                <TableRow>
                  <TableHead>Service</TableHead>
                  <TableHead className="text-right">Qty</TableHead>
                  <TableHead className="text-right">Unit price</TableHead>
                  <TableHead className="text-right">Line total</TableHead>
                  {canEdit ? <TableHead className="w-24" /> : null}
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell>
                      <div className="flex flex-col">
                        <span className="font-medium">
                          {item.service?.name ?? `Service #${item.service_id}`}
                        </span>
                        {item.description ? (
                          <span className="text-muted-foreground text-xs">{item.description}</span>
                        ) : null}
                      </div>
                    </TableCell>
                    <TableCell className="tabular text-right">{item.quantity}</TableCell>
                    <TableCell className="text-right">
                      <MoneyText money={item.unit_price} />
                    </TableCell>
                    <TableCell className="text-right">
                      <MoneyText money={item.line_total} />
                    </TableCell>
                    {canEdit ? (
                      <TableCell className="text-right whitespace-nowrap">
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          aria-label={`Edit ${item.service?.name ?? 'item'}`}
                          onClick={() => setDialog({ open: true, item })}
                        >
                          <PencilIcon aria-hidden="true" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          aria-label={`Remove ${item.service?.name ?? 'item'}`}
                          disabled={items.length === 1}
                          onClick={() => setRemoving(item)}
                        >
                          <Trash2Icon aria-hidden="true" />
                        </Button>
                      </TableCell>
                    ) : null}
                  </TableRow>
                ))}
              </TableBody>
              <TableFooter>
                <TableRow>
                  <TableCell colSpan={3} className="text-right">
                    Subtotal
                  </TableCell>
                  <TableCell className="text-right">
                    <MoneyText money={order.subtotal} />
                  </TableCell>
                  {canEdit ? <TableCell /> : null}
                </TableRow>
                <TableRow>
                  <TableCell colSpan={3} className="text-right">
                    Discount
                  </TableCell>
                  <TableCell className="text-right">
                    <MoneyText money={order.discount} />
                  </TableCell>
                  {canEdit ? <TableCell /> : null}
                </TableRow>
                <TableRow>
                  <TableCell colSpan={3} className="text-right font-semibold">
                    Total
                  </TableCell>
                  <TableCell className="text-right font-semibold" data-testid="items-total">
                    <MoneyText money={order.total} />
                  </TableCell>
                  {canEdit ? <TableCell /> : null}
                </TableRow>
              </TableFooter>
            </Table>
          </div>
        )}
      </CardContent>

      <OrderItemDialog
        open={dialog.open}
        onOpenChange={(open) => setDialog((current) => ({ ...current, open }))}
        orderId={order.id}
        currency={order.currency}
        item={dialog.item}
      />
      <ConfirmDialog
        open={removing !== null}
        onOpenChange={(open) => !open && setRemoving(null)}
        title="Remove this item?"
        description={`${removing?.service?.name ?? 'The item'} is taken off the order and the total is recalculated.`}
        confirmLabel="Remove item"
        destructive
        pending={remove.isPending}
        onConfirm={() => remove.mutateAsync(removing!.id)}
      />
    </Card>
  )
}
