import { useState } from 'react'
import { PencilIcon, Trash2Icon } from 'lucide-react'
import { DataTableRowActions } from '@/components/data-table'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDeleteWorkstation, type Workstation } from '../api'

interface WorkstationRowActionsProps {
  workstation: Workstation
  onEdit: (workstation: Workstation) => void
}

export function WorkstationRowActions({ workstation, onEdit }: WorkstationRowActionsProps) {
  const { can } = useAuth()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const remove = useDeleteWorkstation()

  if (!can('workstations.manage')) return null

  return (
    <>
      <DataTableRowActions label={`Actions for ${workstation.code}`}>
        <DropdownMenuItem onSelect={() => onEdit(workstation)}>
          <PencilIcon aria-hidden="true" />
          Edit
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem variant="destructive" onSelect={() => setConfirmDelete(true)}>
          <Trash2Icon aria-hidden="true" />
          Delete
        </DropdownMenuItem>
      </DataTableRowActions>

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title="Delete this workstation?"
        description={`${workstation.code} will be removed. A workstation with seated users or assigned platform accounts cannot be deleted.`}
        confirmLabel="Delete workstation"
        destructive
        pending={remove.isPending}
        onConfirm={() => remove.mutateAsync(workstation.id)}
      />
    </>
  )
}
