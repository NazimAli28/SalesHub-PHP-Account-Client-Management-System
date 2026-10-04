import { useCallback, useMemo, useState } from 'react'
import { InboxIcon, KeyRoundIcon, PlusIcon } from 'lucide-react'
import { DataTable, DataTableFacetedFilter, useDataTableParams } from '@/components/data-table'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/AuthProvider'
import { Can } from '@/features/auth/Can'
import { accountStandings } from '@/lib/enums'
import {
  PLATFORM_ACCOUNT_LIST_CONFIG,
  usePlatformAccounts,
  useTeamFilterOptions,
  useWorkstationFilterOptions,
} from '../api'
import { BatchDateFilter } from '../components/BatchDateFilter'
import { PlatformAccountFormSheet } from '../components/PlatformAccountFormSheet'
import { RequestAccountsDialog } from '../components/PlatformAccountDialogs'
import { getPlatformAccountColumns } from '../components/platform-account-columns'
import type { PlatformAccount } from '../types'

const ASSIGNED_OPTIONS = [
  { value: '1', label: 'Assigned' },
  { value: '0', label: 'Unassigned' },
]

export default function PlatformAccountsPage() {
  const { can } = useAuth()
  const table = useDataTableParams(PLATFORM_ACCOUNT_LIST_CONFIG)
  const accounts = usePlatformAccounts(table.params)
  const workstations = useWorkstationFilterOptions({ enabled: can('workstations.view') })
  const teams = useTeamFilterOptions({ enabled: can('teams.view') })

  const [sheet, setSheet] = useState<{ open: boolean; account: PlatformAccount | null }>({
    open: false,
    account: null,
  })
  const [requesting, setRequesting] = useState(false)
  const openCreate = () => setSheet({ open: true, account: null })
  const openEdit = useCallback((account: PlatformAccount) => setSheet({ open: true, account }), [])

  const columns = useMemo(() => getPlatformAccountColumns({ onEdit: openEdit }), [openEdit])

  return (
    <div className="space-y-6">
      <PageHeader
        title="Platform accounts"
        description="The shared account inventory: standing, workstation and batch for every account."
        actions={
          <>
            <Can permission="platform-accounts.request-new">
              <Button variant="outline" onClick={() => setRequesting(true)}>
                <InboxIcon aria-hidden="true" />
                Request new accounts
              </Button>
            </Can>
            <Can permission="platform-accounts.create">
              <Button onClick={openCreate}>
                <PlusIcon aria-hidden="true" />
                New account
              </Button>
            </Can>
          </>
        }
      />

      <DataTable
        label="Platform accounts"
        columns={columns}
        query={accounts}
        state={table}
        searchPlaceholder="Search email or Discord username…"
        filters={
          <>
            <DataTableFacetedFilter
              state={table}
              filterKey="standing"
              title="Standing"
              options={accountStandings.options}
            />
            {can('workstations.view') ? (
              <DataTableFacetedFilter
                state={table}
                filterKey="workstation"
                title="Workstation"
                options={workstations.data ?? []}
                isLoading={workstations.isPending}
              />
            ) : null}
            {can('teams.view') ? (
              <DataTableFacetedFilter
                state={table}
                filterKey="team"
                title="Team"
                options={teams.data ?? []}
                isLoading={teams.isPending}
              />
            ) : null}
            <DataTableFacetedFilter
              state={table}
              filterKey="assigned"
              title="Assignment"
              multiple={false}
              options={ASSIGNED_OPTIONS}
            />
            <BatchDateFilter state={table} />
          </>
        }
        emptyState={
          <EmptyState
            icon={KeyRoundIcon}
            title="No platform accounts yet"
            description="Accounts for your workstation or team show up here once support adds them."
            action={
              <Can permission="platform-accounts.request-new">
                <Button size="sm" onClick={() => setRequesting(true)}>
                  <InboxIcon aria-hidden="true" />
                  Request new accounts
                </Button>
              </Can>
            }
          />
        }
      />

      <PlatformAccountFormSheet
        open={sheet.open}
        account={sheet.account}
        onOpenChange={(open) => setSheet((current) => ({ ...current, open }))}
      />
      <Can permission="platform-accounts.request-new">
        <RequestAccountsDialog open={requesting} onOpenChange={setRequesting} />
      </Can>
    </div>
  )
}
