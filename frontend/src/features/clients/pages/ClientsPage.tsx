import { useCallback, useMemo, useState } from 'react'
import { PlusIcon, UsersIcon } from 'lucide-react'
import { DataTable, DataTableFacetedFilter, useDataTableParams } from '@/components/data-table'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/AuthProvider'
import { Can } from '@/features/auth/Can'
import { ExportCsvButton } from '@/features/imports/components/ExportCsvButton'
import { clientStatuses } from '@/lib/enums'
import { CLIENT_LIST_CONFIG, useClients, useOwnerOptions } from '../api'
import { ClientFormSheet } from '../components/ClientFormSheet'
import { getClientColumns } from '../components/client-columns'
import { DateRangeFilter } from '../components/DateRangeFilter'
import type { ClientRecord } from '../types'

const YES_NO = [
  { value: 'true', label: 'Yes' },
  { value: 'false', label: 'No' },
]

export default function ClientsPage() {
  const { can } = useAuth()
  const table = useDataTableParams(CLIENT_LIST_CONFIG)
  const clients = useClients(table.params)
  const owners = useOwnerOptions({ enabled: can('users.view') })

  const [sheet, setSheet] = useState<{ open: boolean; client: ClientRecord | null }>({
    open: false,
    client: null,
  })
  const openCreate = () => setSheet({ open: true, client: null })
  const openEdit = useCallback((client: ClientRecord) => setSheet({ open: true, client }), [])
  const columns = useMemo(() => getClientColumns({ onEdit: openEdit }), [openEdit])

  return (
    <div className="space-y-6">
      <PageHeader
        title="Clients"
        description="Everyone you sell to, with their orders, payments and follow-ups."
        actions={
          <>
            <ExportCsvButton type="clients" params={table.params} />
            <Can permission="clients.create">
              <Button onClick={openCreate}>
                <PlusIcon aria-hidden="true" />
                New client
              </Button>
            </Can>
          </>
        }
      />

      <DataTable
        label="Clients"
        columns={columns}
        query={clients}
        state={table}
        searchPlaceholder="Search name, email or Discord username…"
        filters={
          <>
            <DataTableFacetedFilter
              state={table}
              filterKey="status"
              title="Status"
              options={clientStatuses.options}
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
              filterKey="has_open_orders"
              title="Open orders"
              options={YES_NO}
              multiple={false}
            />
            <DateRangeFilter
              state={table}
              title="Added"
              fromKey="created_from"
              toKey="created_to"
            />
          </>
        }
        emptyState={
          <EmptyState
            icon={UsersIcon}
            title="No clients yet"
            description="Clients you add, or that are assigned to you, show up here."
            action={
              <Can permission="clients.create">
                <Button size="sm" onClick={openCreate}>
                  <PlusIcon aria-hidden="true" />
                  New client
                </Button>
              </Can>
            }
          />
        }
      />

      <ClientFormSheet
        open={sheet.open}
        client={sheet.client}
        onOpenChange={(open) => setSheet((current) => ({ ...current, open }))}
      />
    </div>
  )
}
