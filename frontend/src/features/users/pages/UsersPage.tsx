import { useCallback, useMemo, useState } from 'react'
import { PlusIcon, UsersIcon } from 'lucide-react'
import type { User } from '@/api/types'
import { DataTable, DataTableFacetedFilter, useDataTableParams } from '@/components/data-table'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/AuthProvider'
import { Can } from '@/features/auth/Can'
import { useTeamFilterOptions } from '@/features/teams/api'
import { ROLE_LABELS } from '@/lib/roles'
import { USER_LIST_CONFIG, useUsers } from '../api'
import { ACTIVE_FILTER_OPTIONS } from '../active-options'
import { getUserColumns } from '../components/user-columns'
import { UserFormSheet } from '../components/UserFormSheet'

const ROLE_FILTER_OPTIONS = Object.entries(ROLE_LABELS).map(([value, label]) => ({
  value,
  label,
}))

export default function UsersPage() {
  const { user: me } = useAuth()
  const table = useDataTableParams(USER_LIST_CONFIG)
  const users = useUsers(table.params)
  const teams = useTeamFilterOptions()

  const [sheet, setSheet] = useState<{ open: boolean; user: User | null }>({
    open: false,
    user: null,
  })
  const openCreate = () => setSheet({ open: true, user: null })
  const openEdit = useCallback((user: User) => setSheet({ open: true, user }), [])

  const currentUserId = me?.id
  const columns = useMemo(
    () => getUserColumns({ onEdit: openEdit, currentUserId }),
    [openEdit, currentUserId],
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title="Users"
        description="Everyone who can sign in, with their role, team and workstation."
        actions={
          <Can permission="users.create">
            <Button onClick={openCreate}>
              <PlusIcon aria-hidden="true" />
              New user
            </Button>
          </Can>
        }
      />

      <DataTable
        label="Users"
        columns={columns}
        query={users}
        state={table}
        searchPlaceholder="Search name, username or email…"
        filters={
          <>
            <DataTableFacetedFilter
              state={table}
              filterKey="role"
              title="Role"
              options={ROLE_FILTER_OPTIONS}
            />
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
            icon={UsersIcon}
            title="No users yet"
            description="Accounts you create show up here."
            action={
              <Can permission="users.create">
                <Button size="sm" onClick={openCreate}>
                  <PlusIcon aria-hidden="true" />
                  New user
                </Button>
              </Can>
            }
          />
        }
      />

      <UserFormSheet
        open={sheet.open}
        user={sheet.user}
        onOpenChange={(open) => setSheet((current) => ({ ...current, open }))}
      />
    </div>
  )
}
