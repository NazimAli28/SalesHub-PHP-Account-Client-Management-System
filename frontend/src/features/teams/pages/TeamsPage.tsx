import { useCallback, useMemo, useState } from 'react'
import { PlusIcon, UsersRoundIcon } from 'lucide-react'
import { DataTable, DataTableFacetedFilter, useDataTableParams } from '@/components/data-table'
import { EmptyState } from '@/components/layout/EmptyState'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/button'
import { Can } from '@/features/auth/Can'
import { SHIFT_OPTIONS, TEAM_LIST_CONFIG, useTeams, type Team } from '../api'
import { getTeamColumns } from '../components/team-columns'
import { TeamFormDialog } from '../components/TeamFormDialog'
import { TeamMembersSheet } from '../components/TeamMembersSheet'

export default function TeamsPage() {
  const table = useDataTableParams(TEAM_LIST_CONFIG)
  const teams = useTeams(table.params)

  const [form, setForm] = useState<{ open: boolean; team: Team | null }>({
    open: false,
    team: null,
  })
  const [membersOf, setMembersOf] = useState<Team | null>(null)
  const openCreate = () => setForm({ open: true, team: null })
  const openEdit = useCallback((team: Team) => setForm({ open: true, team }), [])
  const showMembers = useCallback((team: Team) => setMembersOf(team), [])

  const columns = useMemo(
    () => getTeamColumns({ onEdit: openEdit, onShowMembers: showMembers }),
    [openEdit, showMembers],
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title="Teams"
        description="Sales teams with their lead, floor and shift."
        actions={
          <Can permission="teams.manage">
            <Button onClick={openCreate}>
              <PlusIcon aria-hidden="true" />
              New team
            </Button>
          </Can>
        }
      />

      <DataTable
        label="Teams"
        columns={columns}
        query={teams}
        state={table}
        searchPlaceholder="Search teams…"
        filters={
          <DataTableFacetedFilter
            state={table}
            filterKey="shift"
            title="Shift"
            options={SHIFT_OPTIONS}
          />
        }
        emptyState={
          <EmptyState
            icon={UsersRoundIcon}
            title="No teams yet"
            description="Teams group sales executives under a team lead."
            action={
              <Can permission="teams.manage">
                <Button size="sm" onClick={openCreate}>
                  <PlusIcon aria-hidden="true" />
                  New team
                </Button>
              </Can>
            }
          />
        }
      />

      <TeamFormDialog
        open={form.open}
        team={form.team}
        onOpenChange={(open) => setForm((current) => ({ ...current, open }))}
      />
      <TeamMembersSheet team={membersOf} onClose={() => setMembersOf(null)} />
    </div>
  )
}
