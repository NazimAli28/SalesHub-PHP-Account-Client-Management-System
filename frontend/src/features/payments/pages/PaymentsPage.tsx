import { useMemo, useState } from 'react'
import { WalletIcon } from 'lucide-react'
import { Navigate, useSearchParams } from 'react-router'
import { DataTable, DataTableFacetedFilter, useDataTableParams } from '@/components/data-table'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { useAuth } from '@/features/auth/AuthProvider'
import { useOwnerOptions, useTeamOptions } from '@/features/clients/api'
import { DateRangeFilter } from '@/features/clients/components/DateRangeFilter'
import { paymentStatuses } from '@/lib/enums'
import { PAYMENT_LIST_CONFIG, usePayments } from '../api'
import { getPaymentColumns } from '../components/payment-columns'

const OVERDUE_ONLY = [{ value: 'true', label: 'Overdue only' }]

/** By default the list shows what still has to be collected: scheduled (due and overdue) installments. */
const DEFAULT_STATUS = 'scheduled'

function hasListParams(searchParams: URLSearchParams): boolean {
  return (
    searchParams.has('q') || PAYMENT_LIST_CONFIG.filterKeys.some((key) => searchParams.has(key))
  )
}

export default function PaymentsPage() {
  const { can } = useAuth()
  const [searchParams] = useSearchParams()
  const table = useDataTableParams(PAYMENT_LIST_CONFIG)

  // First visit without any list params: redirect once to the default view (before any request).
  // Clearing the filter afterwards shows everything, because the seed is only checked once.
  const [needsSeed, setNeedsSeed] = useState(() => !hasListParams(searchParams))
  if (needsSeed && hasListParams(searchParams)) setNeedsSeed(false)

  const payments = usePayments(table.params, { enabled: !needsSeed })
  const owners = useOwnerOptions({ enabled: can('users.view') })
  const teams = useTeamOptions()
  const columns = useMemo(() => getPaymentColumns(), [])

  if (needsSeed) return <Navigate replace to={{ search: `?status=${DEFAULT_STATUS}` }} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="Payments"
        description="Installments to collect across your orders. Overdue ones are highlighted."
      />

      <DataTable
        label="Payments"
        columns={columns}
        query={payments}
        state={table}
        searchPlaceholder="Search order number, client or reference…"
        filters={
          <>
            <DataTableFacetedFilter
              state={table}
              filterKey="status"
              title="Status"
              options={paymentStatuses.options}
            />
            <DataTableFacetedFilter
              state={table}
              filterKey="overdue"
              title="Overdue"
              options={OVERDUE_ONLY}
              multiple={false}
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
            <DateRangeFilter state={table} title="Due date" fromKey="due_from" toKey="due_to" />
          </>
        }
        emptyState={
          <EmptyState
            icon={WalletIcon}
            title="No installments to show"
            description="Schedule installments from an order to see them here."
          />
        }
      />
    </div>
  )
}
