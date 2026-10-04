import { useMemo, useState } from 'react'
import { PlusIcon, ReceiptIcon } from 'lucide-react'
import { useNavigate } from 'react-router'
import { detailPath } from '@/app/paths'
import { DataTable, DataTableFacetedFilter, useDataTableParams } from '@/components/data-table'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/AuthProvider'
import { Can } from '@/features/auth/Can'
import { useOwnerOptions, useTeamOptions } from '@/features/clients/api'
import { DateRangeFilter } from '@/features/clients/components/DateRangeFilter'
import { orderStatuses } from '@/lib/enums'
import { ORDER_LIST_CONFIG, useOrders } from '../api'
import { ClientFilter } from '../components/ClientFilter'
import { OrderFormSheet } from '../components/OrderFormSheet'
import { getOrderColumns } from '../components/order-columns'

const OVERDUE_ONLY = [{ value: 'true', label: 'Has overdue payments' }]

export default function OrdersPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const table = useDataTableParams(ORDER_LIST_CONFIG)
  const orders = useOrders(table.params)
  const owners = useOwnerOptions({ enabled: can('users.view') })
  const teams = useTeamOptions()
  const [creating, setCreating] = useState(false)
  const columns = useMemo(() => getOrderColumns(), [])

  return (
    <div className="space-y-6">
      <PageHeader
        title="Orders"
        description="Every order with its status, what has been paid and what is still owed."
        actions={
          <Can permission="orders.create">
            <Button onClick={() => setCreating(true)}>
              <PlusIcon aria-hidden="true" />
              New order
            </Button>
          </Can>
        }
      />

      <DataTable
        label="Orders"
        columns={columns}
        query={orders}
        state={table}
        searchPlaceholder="Search order number or client…"
        filters={
          <>
            <DataTableFacetedFilter
              state={table}
              filterKey="status"
              title="Status"
              options={orderStatuses.options}
            />
            {can('users.view') ? (
              <DataTableFacetedFilter
                state={table}
                filterKey="owner"
                title="Owner"
                options={owners.data ?? []}
                isLoading={owners.isPending}
              />
            ) : null}
            <DataTableFacetedFilter
              state={table}
              filterKey="team"
              title="Team"
              options={teams.data ?? []}
              isLoading={teams.isPending}
            />
            <ClientFilter state={table} />
            <DataTableFacetedFilter
              state={table}
              filterKey="has_overdue"
              title="Overdue"
              options={OVERDUE_ONLY}
              multiple={false}
            />
            <DateRangeFilter
              state={table}
              title="Ordered"
              fromKey="ordered_from"
              toKey="ordered_to"
            />
          </>
        }
        emptyState={
          <EmptyState
            icon={ReceiptIcon}
            title="No orders yet"
            description="Orders you create, or that are assigned to you, show up here."
            action={
              <Can permission="orders.create">
                <Button size="sm" onClick={() => setCreating(true)}>
                  <PlusIcon aria-hidden="true" />
                  New order
                </Button>
              </Can>
            }
          />
        }
      />

      <OrderFormSheet
        open={creating}
        onOpenChange={setCreating}
        onCreated={(order) => void navigate(detailPath.order(order.id))}
      />
    </div>
  )
}
