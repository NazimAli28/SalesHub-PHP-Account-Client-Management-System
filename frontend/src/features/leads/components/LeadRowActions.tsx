import { useState } from 'react'
import { CopyIcon, PencilIcon, Trash2Icon } from 'lucide-react'
import { toast } from 'sonner'
import type { Lead } from '@/api/types'
import { DataTableRowActions } from '@/components/data-table'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDeleteLead } from '../api'

interface LeadRowActionsProps {
  lead: Lead
  onEdit: (lead: Lead) => void
}

/**
 * Row menu. Each item is gated by permission; "request-change" users see the same items, and
 * their edits/deletes are queued for approval by the API (202).
 */
export function LeadRowActions({ lead, onEdit }: LeadRowActionsProps) {
  const { can, canAny } = useAuth()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const remove = useDeleteLead()

  const canEdit = canAny(['leads.update', 'leads.request-change'])
  const canDelete = canAny(['leads.delete', 'leads.request-change'])
  const deleteNeedsApproval = !can('leads.delete')
  const hasPendingChange = lead.pending_change !== null
  const clientName = lead.client?.name ?? lead.client?.discord_username ?? `lead #${lead.id}`

  return (
    <>
      <DataTableRowActions label={`Actions for ${clientName}`}>
        {canEdit ? (
          <DropdownMenuItem disabled={hasPendingChange} onSelect={() => onEdit(lead)}>
            <PencilIcon aria-hidden="true" />
            Edit
          </DropdownMenuItem>
        ) : null}
        <DropdownMenuItem
          onSelect={() => {
            void navigator.clipboard?.writeText(String(lead.id))
            toast.success(`Copied lead ID ${lead.id}`)
          }}
        >
          <CopyIcon aria-hidden="true" />
          Copy lead ID
        </DropdownMenuItem>
        {canDelete ? (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              variant="destructive"
              disabled={hasPendingChange}
              onSelect={() => setConfirmDelete(true)}
            >
              <Trash2Icon aria-hidden="true" />
              {deleteNeedsApproval ? 'Request deletion' : 'Delete'}
            </DropdownMenuItem>
          </>
        ) : null}
      </DataTableRowActions>

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={deleteNeedsApproval ? 'Request deletion of this lead?' : 'Delete this lead?'}
        description={
          deleteNeedsApproval
            ? `A reviewer must approve deleting the lead for ${clientName} before it is removed.`
            : `The lead for ${clientName} will be removed from the pipeline. This cannot be undone here.`
        }
        confirmLabel={deleteNeedsApproval ? 'Send request' : 'Delete lead'}
        destructive
        pending={remove.isPending}
        onConfirm={() => remove.mutateAsync(lead.id)}
      />
    </>
  )
}
