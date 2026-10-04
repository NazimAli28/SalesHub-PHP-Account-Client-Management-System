import { useCallback, useMemo, useState } from 'react'
import { ScrollTextIcon } from 'lucide-react'
import { DataTable, DataTableFacetedFilter, useDataTableParams } from '@/components/data-table'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { Input } from '@/components/ui/input'
import { useAuth } from '@/features/auth/AuthProvider'
import {
  AUDIT_LIST_CONFIG,
  EVENT_OPTIONS,
  LOG_NAME_OPTIONS,
  SUBJECT_TYPE_OPTIONS,
  useAuditLog,
  useCauserOptions,
  type Activity,
} from '../api'
import { ActivityDetailSheet } from '../components/ActivityDetailSheet'
import { getAuditColumns } from '../components/audit-columns'

export default function AuditLogPage() {
  const { can } = useAuth()
  const table = useDataTableParams(AUDIT_LIST_CONFIG)
  const log = useAuditLog(table.params)
  const causers = useCauserOptions({ enabled: can('users.view') })
  const [selected, setSelected] = useState<Activity | null>(null)

  const openDetails = useCallback((activity: Activity) => setSelected(activity), [])
  const columns = useMemo(() => getAuditColumns({ onOpen: openDetails }), [openDetails])

  const from = table.params.filters.from?.[0] ?? ''
  const to = table.params.filters.to?.[0] ?? ''

  return (
    <div className="space-y-6">
      <PageHeader
        title="Audit log"
        description="A read-only trail of who did what: record changes, sign-ins, approvals and credential reveals."
      />

      <DataTable
        label="Audit log"
        columns={columns}
        query={log}
        state={table}
        searchPlaceholder="Search descriptions…"
        filters={
          <>
            <DataTableFacetedFilter
              state={table}
              filterKey="causer"
              title="User"
              options={causers.data ?? []}
              isLoading={causers.isPending}
              multiple={false}
            />
            <DataTableFacetedFilter
              state={table}
              filterKey="subject_type"
              title="Subject"
              options={SUBJECT_TYPE_OPTIONS}
              multiple={false}
            />
            <DataTableFacetedFilter
              state={table}
              filterKey="log_name"
              title="Log"
              options={LOG_NAME_OPTIONS}
            />
            <DataTableFacetedFilter
              state={table}
              filterKey="event"
              title="Event"
              options={EVENT_OPTIONS}
            />
            <div className="flex items-center gap-1.5">
              <Input
                type="date"
                aria-label="From date"
                className="w-36"
                value={from}
                max={to || undefined}
                onChange={(event) =>
                  table.setFilter('from', event.target.value ? [event.target.value] : [])
                }
              />
              <span aria-hidden="true" className="text-muted-foreground text-sm">
                to
              </span>
              <Input
                type="date"
                aria-label="To date"
                className="w-36"
                value={to}
                min={from || undefined}
                onChange={(event) =>
                  table.setFilter('to', event.target.value ? [event.target.value] : [])
                }
              />
            </div>
          </>
        }
        emptyState={
          <EmptyState
            icon={ScrollTextIcon}
            title="Nothing logged yet"
            description="Activity shows up here as people use the app."
          />
        }
      />

      <ActivityDetailSheet activity={selected} onClose={() => setSelected(null)} />
    </div>
  )
}
