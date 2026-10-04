import { useState } from 'react'
import { ExternalLinkIcon, PencilIcon, Trash2Icon } from 'lucide-react'
import { Link } from 'react-router'
import { detailPath } from '@/app/paths'
import { DataTableRowActions } from '@/components/data-table'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDeleteClient } from '../api'
import { clientDisplayName, type ClientRecord } from '../types'

interface ClientRowActionsProps {
  client: ClientRecord
  onEdit: (client: ClientRecord) => void
}

/** Row menu: open, edit, delete. Request-change users get the same items (queued, 202). */
export function ClientRowActions({ client, onEdit }: ClientRowActionsProps) {
  const { can, canAny } = useAuth()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const remove = useDeleteClient()

  const canEdit = canAny(['clients.update', 'clients.request-change'])
  const canDelete = canAny(['clients.delete', 'clients.request-change'])
  const deleteNeedsApproval = !can('clients.delete')
  const hasPendingChange = client.pending_change !== null
  const name = clientDisplayName(client)

  return (
    <>
      <DataTableRowActions label={`Actions for ${name}`}>
        <DropdownMenuItem asChild>
          <Link to={detailPath.client(client.id)}>
            <ExternalLinkIcon aria-hidden="true" />
            Open client
          </Link>
        </DropdownMenuItem>
        {canEdit ? (
          <DropdownMenuItem disabled={hasPendingChange} onSelect={() => onEdit(client)}>
            <PencilIcon aria-hidden="true" />
            Edit
          </DropdownMenuItem>
        ) : null}
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
        title={deleteNeedsApproval ? 'Request deletion of this client?' : 'Delete this client?'}
        description={
          deleteNeedsApproval
            ? `A reviewer must approve deleting ${name} before they are removed.`
            : `${name} will be removed from your clients. This cannot be undone here.`
        }
        confirmLabel={deleteNeedsApproval ? 'Send request' : 'Delete client'}
        destructive
        pending={remove.isPending}
        onConfirm={() => remove.mutateAsync(client.id)}
      />
    </>
  )
}
