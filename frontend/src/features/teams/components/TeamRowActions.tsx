import { useState } from 'react'
import { PencilIcon, Trash2Icon, UsersIcon } from 'lucide-react'
import { DataTableRowActions } from '@/components/data-table'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDeleteTeam, type Team } from '../api'

interface TeamRowActionsProps {
  team: Team
  onEdit: (team: Team) => void
  onShowMembers: (team: Team) => void
}

export function TeamRowActions({ team, onEdit, onShowMembers }: TeamRowActionsProps) {
  const { can } = useAuth()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const remove = useDeleteTeam()
  const canManage = can('teams.manage')

  return (
    <>
      <DataTableRowActions label={`Actions for ${team.name}`}>
        <DropdownMenuItem onSelect={() => onShowMembers(team)}>
          <UsersIcon aria-hidden="true" />
          View members
        </DropdownMenuItem>
        {canManage ? (
          <>
            <DropdownMenuItem onSelect={() => onEdit(team)}>
              <PencilIcon aria-hidden="true" />
              Edit
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem variant="destructive" onSelect={() => setConfirmDelete(true)}>
              <Trash2Icon aria-hidden="true" />
              Delete
            </DropdownMenuItem>
          </>
        ) : null}
      </DataTableRowActions>

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title="Delete this team?"
        description={`${team.name} will be removed. A team that still has members or workstations cannot be deleted.`}
        confirmLabel="Delete team"
        destructive
        pending={remove.isPending}
        onConfirm={() => remove.mutateAsync(team.id)}
      />
    </>
  )
}
