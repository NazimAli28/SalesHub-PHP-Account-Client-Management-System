import { useState, type ReactNode } from 'react'
import { ClockIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router'
import { isApiError } from '@/api/errors'
import type { Lead } from '@/api/types'
import { detailPath, paths } from '@/app/paths'
import { MoneyText } from '@/components/data-display/MoneyText'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { StatusBadge } from '@/components/data-display/StatusBadge'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { EmptyState } from '@/components/layout/EmptyState'
import { ErrorState } from '@/components/layout/ErrorState'
import { CardSkeleton } from '@/components/layout/LoadingSkeleton'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useAuth } from '@/features/auth/AuthProvider'
import { Can } from '@/features/auth/Can'
import { OrderFormSheet } from '@/features/orders/components/OrderFormSheet'
import { OverdueFlag } from '@/features/payments/components/OverdueFlag'
import type { OrderSummary, PaymentSummary } from '@/features/payments/types'
import { formatRelative } from '@/lib/format'
import { cn } from '@/lib/utils'
import { useClient, useDeleteClient } from '../api'
import { ClientFormSheet } from '../components/ClientFormSheet'
import { ClientNotes } from '../components/ClientNotes'
import { ClientTimeline } from '../components/ClientTimeline'
import { KpiCard } from '../components/KpiCard'
import { clientDisplayName, type ClientDetail } from '../types'

/** Orders that no longer count towards what the client owes. */
const CLOSED_ORDER_STATUSES = new Set(['cancelled', 'refunded'])

function openBalanceCents(orders: readonly OrderSummary[]): number {
  return orders
    .filter((order) => !CLOSED_ORDER_STATUSES.has(order.status?.value ?? ''))
    .reduce((sum, order) => sum + (order.balance.amount_cents ?? 0), 0)
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-[7.5rem_1fr] gap-2 py-2 text-sm">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="min-w-0 break-words">{children}</dd>
    </div>
  )
}

export default function ClientDetailPage() {
  const { clientId } = useParams()
  const id = Number(clientId)
  const clientQuery = useClient(id)
  const navigate = useNavigate()
  const { can, canAny } = useAuth()
  const [editing, setEditing] = useState(false)
  const [creatingOrder, setCreatingOrder] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const remove = useDeleteClient()

  if (clientQuery.isPending) {
    return (
      <div className="space-y-4" role="status" aria-label="Loading client">
        <CardSkeleton />
        <CardSkeleton />
      </div>
    )
  }
  if (clientQuery.isError) {
    const error = clientQuery.error
    const denied = isApiError(error) && (error.isForbidden || error.isNotFound)
    return (
      <ErrorState
        title={denied ? "You don't have access to this record" : 'Could not load this client'}
        error={denied ? undefined : error}
        onRetry={denied ? undefined : () => void clientQuery.refetch()}
      />
    )
  }

  const client: ClientDetail = clientQuery.data
  const name = clientDisplayName(client)
  const orders = client.orders ?? []
  const leads = client.leads ?? []
  const overdue = client.overdue_payments ?? []
  const upcoming = client.upcoming_payments ?? []
  const pending = client.pending_change
  const canEdit = canAny(['clients.update', 'clients.request-change'])
  const canDelete = canAny(['clients.delete', 'clients.request-change'])
  const deleteNeedsApproval = !can('clients.delete')

  return (
    <div className="space-y-6">
      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-3">
            {name}
            <StatusBadge kind="clientStatus" value={client.status} />
          </span>
        }
        description={`@${client.discord_username}${client.email ? ` · ${client.email}` : ''}`}
        actions={
          <>
            <Can permission="orders.create">
              <Button onClick={() => setCreatingOrder(true)}>
                <PlusIcon aria-hidden="true" />
                New order
              </Button>
            </Can>
            {canEdit ? (
              <Button
                variant="outline"
                disabled={pending !== null}
                onClick={() => setEditing(true)}
              >
                <PencilIcon aria-hidden="true" />
                {can('clients.update') ? 'Edit' : 'Request change'}
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
            {formatRelative(pending.requested_at)}.
          </CardContent>
        </Card>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard
          label="Open balance"
          value={<MoneyText cents={openBalanceCents(orders)} />}
          hint="Still owed across open orders"
        />
        <KpiCard
          label="Overdue payments"
          value={client.counts.overdue_payments}
          tone={client.counts.overdue_payments > 0 ? 'danger' : 'default'}
          hint={client.counts.overdue_payments > 0 ? 'Past their due date' : 'Nothing is late'}
        />
        <KpiCard
          label="Orders"
          value={client.counts.orders}
          hint={`${client.counts.open_orders} open`}
        />
        <KpiCard label="Leads" value={client.counts.leads} />
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,22rem)_1fr]">
        <Card>
          <CardHeader>
            <CardTitle>Profile</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div>
              <p className="text-muted-foreground text-sm">Lifetime value</p>
              <p className="text-3xl font-semibold tracking-tight">
                <MoneyText money={client.lifetime_value} />
              </p>
            </div>
            <dl className="divide-y">
              <Detail label="Discord">@{client.discord_username}</Detail>
              <Detail label="Email">{client.email ?? '—'}</Detail>
              <Detail label="Payment name">{client.payment_name ?? '—'}</Detail>
              <Detail label="Country">{client.country ?? '—'}</Detail>
              <Detail label="Owner">{client.owner?.name ?? '—'}</Detail>
              <Detail label="Client since">
                <RelativeTime value={client.created_at} display="date" />
              </Detail>
            </dl>
          </CardContent>
        </Card>

        <Tabs defaultValue="orders">
          <TabsList className="h-auto flex-wrap">
            <TabsTrigger value="orders">Orders ({orders.length})</TabsTrigger>
            <TabsTrigger value="payments">
              Payments ({overdue.length + upcoming.length})
            </TabsTrigger>
            <TabsTrigger value="leads">Leads ({leads.length})</TabsTrigger>
            <TabsTrigger value="notes">Notes</TabsTrigger>
            <TabsTrigger value="activity">Activity</TabsTrigger>
          </TabsList>

          <TabsContent value="orders">
            <OrdersTab orders={orders} />
          </TabsContent>
          <TabsContent value="payments">
            <PaymentsTab overdue={overdue} upcoming={upcoming} />
          </TabsContent>
          <TabsContent value="leads">
            <LeadsTab leads={leads} />
          </TabsContent>
          <TabsContent value="notes">
            <NotesTab client={client} />
          </TabsContent>
          <TabsContent value="activity">
            <ClientTimeline clientId={client.id} enabled />
          </TabsContent>
        </Tabs>
      </div>

      <ClientFormSheet open={editing} onOpenChange={setEditing} client={client} />
      <OrderFormSheet
        open={creatingOrder}
        onOpenChange={setCreatingOrder}
        client={{ id: client.id, label: name }}
        onCreated={(order) => void navigate(detailPath.order(order.id))}
      />
      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={deleteNeedsApproval ? 'Request deletion of this client?' : 'Delete this client?'}
        description={
          deleteNeedsApproval
            ? `A reviewer must approve deleting ${name} before they are removed.`
            : `${name} will be removed from your clients. This cannot be undone here.`
        }
        confirmLabel={deleteNeedsApproval ? 'Send request' : 'Delete client'}
        destructive
        pending={remove.isPending}
        onConfirm={async () => {
          // A 202 keeps the client (it waits for approval); only a real delete leaves the page.
          const result = await remove.mutateAsync(client.id)
          if (result.kind === 'applied') void navigate(paths.clients)
        }}
      />
    </div>
  )
}

// ---------------------------------------------------------------------------
// Tabs
// ---------------------------------------------------------------------------

function OrdersTab({ orders }: { orders: OrderSummary[] }) {
  if (orders.length === 0) {
    return <EmptyState title="No orders yet" description="Orders for this client show up here." />
  }
  return (
    <Card className="p-0">
      <div className="overflow-x-auto">
        <Table aria-label="Client orders">
          <TableHeader>
            <TableRow>
              <TableHead>Order</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Ordered</TableHead>
              <TableHead className="text-right">Total</TableHead>
              <TableHead className="text-right">Paid</TableHead>
              <TableHead className="text-right">Balance</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {orders.map((order) => (
              <TableRow key={order.id}>
                <TableCell>
                  <Link
                    to={detailPath.order(order.id)}
                    className="font-medium hover:underline focus-visible:underline"
                  >
                    {order.order_number}
                  </Link>
                </TableCell>
                <TableCell>
                  <StatusBadge kind="orderStatus" value={order.status} />
                </TableCell>
                <TableCell>
                  <RelativeTime value={order.ordered_on} display="date" />
                </TableCell>
                <TableCell className="text-right">
                  <MoneyText money={order.total} />
                </TableCell>
                <TableCell className="text-right">
                  <MoneyText money={order.amount_paid} />
                </TableCell>
                <TableCell className="text-right">
                  <div className="flex items-center justify-end gap-2">
                    {order.overdue_payments_count > 0 ? <OverdueFlag /> : null}
                    <MoneyText money={order.balance} />
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </Card>
  )
}

function PaymentRows({ payments, overdue }: { payments: PaymentSummary[]; overdue: boolean }) {
  return (
    <Table aria-label={overdue ? 'Overdue payments' : 'Upcoming payments'}>
      <TableHeader>
        <TableRow>
          <TableHead>Order</TableHead>
          <TableHead>Due</TableHead>
          <TableHead className="text-right">Amount</TableHead>
          <TableHead>Status</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {payments.map((payment) => (
          <TableRow
            key={payment.id}
            data-overdue={overdue || undefined}
            className={cn(overdue && 'bg-destructive/5 hover:bg-destructive/10')}
          >
            <TableCell>
              <Link
                to={detailPath.order(payment.order_id)}
                className="font-medium hover:underline focus-visible:underline"
              >
                {payment.order_number ?? `Order #${payment.order_id}`}
              </Link>{' '}
              <span className="text-muted-foreground">· installment {payment.sequence}</span>
            </TableCell>
            <TableCell className={cn(overdue && 'text-destructive font-medium')}>
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
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}

function PaymentsTab({
  overdue,
  upcoming,
}: {
  overdue: PaymentSummary[]
  upcoming: PaymentSummary[]
}) {
  if (overdue.length === 0 && upcoming.length === 0) {
    return (
      <EmptyState
        title="No payments due"
        description="Scheduled installments for this client show up here."
      />
    )
  }
  return (
    <div className="space-y-4">
      {overdue.length > 0 ? (
        <Card className="border-destructive/30 p-0">
          <CardHeader className="pt-4">
            <CardTitle className="text-destructive">Overdue ({overdue.length})</CardTitle>
          </CardHeader>
          <div className="overflow-x-auto">
            <PaymentRows payments={overdue} overdue />
          </div>
        </Card>
      ) : null}
      {upcoming.length > 0 ? (
        <Card className="p-0">
          <CardHeader className="pt-4">
            <CardTitle>Upcoming ({upcoming.length})</CardTitle>
          </CardHeader>
          <div className="overflow-x-auto">
            <PaymentRows payments={upcoming} overdue={false} />
          </div>
        </Card>
      ) : null}
    </div>
  )
}

function LeadsTab({ leads }: { leads: Lead[] }) {
  if (leads.length === 0) {
    return (
      <EmptyState title="No leads yet" description="Conversations with this client show up here." />
    )
  }
  return (
    <Card className="p-0">
      <div className="overflow-x-auto">
        <Table aria-label="Client leads">
          <TableHeader>
            <TableRow>
              <TableHead>Stage</TableHead>
              <TableHead className="text-right">Estimated value</TableHead>
              <TableHead>Contacted</TableHead>
              <TableHead>Follow-up</TableHead>
              <TableHead>Owner</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {leads.map((lead) => (
              <TableRow key={lead.id}>
                <TableCell>
                  <StatusBadge kind="leadStage" value={lead.stage} />
                </TableCell>
                <TableCell className="text-right">
                  <MoneyText money={lead.estimated_value} />
                </TableCell>
                <TableCell>
                  <RelativeTime value={lead.contacted_on} display="date" />
                </TableCell>
                <TableCell>
                  <RelativeTime value={lead.next_follow_up_on} display="date" />
                </TableCell>
                <TableCell className="whitespace-nowrap">{lead.owner?.name ?? '—'}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </Card>
  )
}

function NotesTab({ client }: { client: ClientDetail }) {
  return (
    <div className="space-y-4">
      <ClientNotes clientId={client.id} />
      <ProfileNotes client={client} />
    </div>
  )
}

/** Free-text fields from the client record (edited through the Edit sheet). */
function ProfileNotes({ client }: { client: ClientDetail }) {
  const hasContent =
    client.notes || client.next_upsell_plan || client.lost_note || client.expected_upsell_on
  if (!hasContent && client.nurturing_rating === null) return null
  return (
    <Card>
      <CardHeader>
        <CardTitle>Profile notes</CardTitle>
      </CardHeader>
      <CardContent>
        <dl className="divide-y">
          <Detail label="Nurturing">
            {client.nurturing_rating === null ? '—' : `${client.nurturing_rating} / 100`}
          </Detail>
          <Detail label="Expected upsell">
            <RelativeTime value={client.expected_upsell_on} display="date" />
          </Detail>
          <Detail label="Upsell plan">
            <span className="whitespace-pre-wrap">{client.next_upsell_plan ?? '—'}</span>
          </Detail>
          {client.lost_note ? (
            <Detail label="Lost note">
              <span className="whitespace-pre-wrap">{client.lost_note}</span>
            </Detail>
          ) : null}
          <Detail label="Notes">
            <span className="whitespace-pre-wrap">{client.notes ?? '—'}</span>
          </Detail>
        </dl>
      </CardContent>
    </Card>
  )
}
