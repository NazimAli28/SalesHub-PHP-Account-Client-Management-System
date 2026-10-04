import { useCallback, useMemo, useState } from 'react'
import { PackageIcon, PlusIcon } from 'lucide-react'
import { DataTable, DataTableFacetedFilter, useDataTableParams } from '@/components/data-table'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/AuthProvider'
import { Can } from '@/features/auth/Can'
import { ACTIVE_FILTER_OPTIONS } from '@/features/users/active-options'
import { SERVICE_CATEGORY_OPTIONS, SERVICE_LIST_CONFIG, useServices, type Service } from '../api'
import { ServiceFormSheet } from '../components/ServiceFormSheet'
import { getServiceColumns } from '../components/service-columns'

export default function ServicesPage() {
  const { can } = useAuth()
  const canManage = can('services.manage')
  const table = useDataTableParams(SERVICE_LIST_CONFIG)
  const services = useServices(table.params)

  const [sheet, setSheet] = useState<{ open: boolean; service: Service | null }>({
    open: false,
    service: null,
  })
  const openCreate = () => setSheet({ open: true, service: null })
  const openEdit = useCallback((service: Service) => setSheet({ open: true, service }), [])

  const columns = useMemo(
    () => getServiceColumns({ onEdit: openEdit, showActions: canManage }),
    [openEdit, canManage],
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title="Services"
        description={
          canManage
            ? 'The catalog of services the team sells, with base prices.'
            : 'The catalog of services the team sells, with base prices. Read-only.'
        }
        actions={
          <Can permission="services.manage">
            <Button onClick={openCreate}>
              <PlusIcon aria-hidden="true" />
              New service
            </Button>
          </Can>
        }
      />

      <DataTable
        label="Services"
        columns={columns}
        query={services}
        state={table}
        searchPlaceholder="Search name, slug or description…"
        filters={
          <>
            <DataTableFacetedFilter
              state={table}
              filterKey="category"
              title="Category"
              options={SERVICE_CATEGORY_OPTIONS}
            />
            <DataTableFacetedFilter
              state={table}
              filterKey="active"
              title="Status"
              options={ACTIVE_FILTER_OPTIONS}
              multiple={false}
            />
          </>
        }
        emptyState={
          <EmptyState
            icon={PackageIcon}
            title="No services yet"
            description="Services you add to the catalog can be put on orders."
            action={
              <Can permission="services.manage">
                <Button size="sm" onClick={openCreate}>
                  <PlusIcon aria-hidden="true" />
                  New service
                </Button>
              </Can>
            }
          />
        }
      />

      <ServiceFormSheet
        open={sheet.open}
        service={sheet.service}
        onOpenChange={(open) => setSheet((current) => ({ ...current, open }))}
      />
    </div>
  )
}
