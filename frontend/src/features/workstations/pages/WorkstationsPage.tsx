import { useCallback, useMemo, useState } from 'react'
import { MonitorIcon, PlusIcon } from 'lucide-react'
import { DataTable, DataTableFacetedFilter, useDataTableParams } from '@/components/data-table'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/AuthProvider'
import { Can } from '@/features/auth/Can'
import { useTeamFilterOptions } from '@/features/teams/api'
import { ACTIVE_FILTER_OPTIONS } from '@/features/users/active-options'
import { WORKSTATION_LIST_CONFIG, useWorkstations, type Workstation } from '../api'
import { WorkstationFormDialog } from '../components/WorkstationFormDialog'
import { getWorkstationColumns } from '../components/workstation-columns'

export default function WorkstationsPage() {
  const { can } = useAuth()
  const canManage = can('workstations.manage')
  const table = useDataTableParams(WORKSTATION_LIST_CONFIG)
  const workstations = useWorkstations(table.params)
  const teams = useTeamFilterOptions()

  const [form, setForm] = useState<{ open: boolean; workstation: Workstation | null }>({
    open: false,
    workstation: null,
  })
  const openCreate = () => setForm({ open: true, workstation: null })
  const openEdit = useCallback(
    (workstation: Workstation) => setForm({ open: true, workstation }),
    [],
  )

  const columns = useMemo(
    () => getWorkstationColumns({ onEdit: openEdit, showActions: canManage }),
    [openEdit, canManage],
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title="Workstations"
        description="Seats that users sit at and platform accounts are assigned to."
        actions={
          <Can permission="workstations.manage">
            <Button onClick={openCreate}>
              <PlusIcon aria-hidden="true" />
              New workstation
            </Button>
          </Can>
        }
      />

      <DataTable
        label="Workstations"
        columns={columns}
        query={workstations}
        state={table}
        searchPlaceholder="Search code or label…"
        filters={
          <>
            <DataTableFacetedFilter
              state={table}
              filterKey="team"
              title="Team"
              options={teams.data ?? []}
              isLoading={teams.isPending}
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
            icon={MonitorIcon}
            title="No workstations yet"
            description="Add workstations so users and platform accounts can be seated."
            action={
              <Can permission="workstations.manage">
                <Button size="sm" onClick={openCreate}>
                  <PlusIcon aria-hidden="true" />
                  New workstation
                </Button>
              </Can>
            }
          />
        }
      />

      <WorkstationFormDialog
        open={form.open}
        workstation={form.workstation}
        onOpenChange={(open) => setForm((current) => ({ ...current, open }))}
      />
    </div>
  )
}
