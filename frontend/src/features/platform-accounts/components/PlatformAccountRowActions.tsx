import { useState } from 'react'
import { EyeIcon, GaugeIcon, MapPinIcon, PencilIcon, Trash2Icon } from 'lucide-react'
import { useNavigate } from 'react-router'
import { detailPath } from '@/app/paths'
import { DataTableRowActions } from '@/components/data-table'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDeletePlatformAccount } from '../api'
import type { PlatformAccount } from '../types'
import { AssignWorkstationDialog, ChangeStandingDialog } from './PlatformAccountDialogs'

interface PlatformAccountRowActionsProps {
  account: PlatformAccount
  onEdit: (account: PlatformAccount) => void
}

/**
 * Row menu. Every item is gated by permission. "request-change" users see edit, standing and
 * delete too, and their changes are queued for approval by the API (202).
 */
export function PlatformAccountRowActions({ account, onEdit }: PlatformAccountRowActionsProps) {
  const { can, canAny } = useAuth()
  const navigate = useNavigate()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [assigning, setAssigning] = useState(false)
  const [changingStanding, setChangingStanding] = useState(false)
  const remove = useDeletePlatformAccount()

  const canEdit = canAny(['platform-accounts.update', 'platform-accounts.request-change'])
  const canAssign = can('platform-accounts.assign')
  const canChangeStanding = canAny([
    'platform-accounts.change-standing',
    'platform-accounts.request-change',
  ])
  const canDelete = canAny(['platform-accounts.delete', 'platform-accounts.request-change'])
  const deleteNeedsApproval = !can('platform-accounts.delete')
  const hasPendingChange = Boolean(account.pending_change)

  return (
    <>
      <DataTableRowActions label={`Actions for ${account.email}`}>
        <DropdownMenuItem onSelect={() => void navigate(detailPath.platformAccount(account.id))}>
          <EyeIcon aria-hidden="true" />
          View
        </DropdownMenuItem>
        {canEdit ? (
          <DropdownMenuItem disabled={hasPendingChange} onSelect={() => onEdit(account)}>
            <PencilIcon aria-hidden="true" />
            {can('platform-accounts.update') ? 'Edit' : 'Request edit'}
          </DropdownMenuItem>
        ) : null}
        {canAssign ? (
          <DropdownMenuItem onSelect={() => setAssigning(true)}>
            <MapPinIcon aria-hidden="true" />
            Assign workstation
          </DropdownMenuItem>
        ) : null}
        {canChangeStanding ? (
          <DropdownMenuItem disabled={hasPendingChange} onSelect={() => setChangingStanding(true)}>
            <GaugeIcon aria-hidden="true" />
            Change standing
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

      {canAssign ? (
        <AssignWorkstationDialog account={account} open={assigning} onOpenChange={setAssigning} />
      ) : null}
      {canChangeStanding ? (
        <ChangeStandingDialog
          account={account}
          open={changingStanding}
          onOpenChange={setChangingStanding}
        />
      ) : null}

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={
          deleteNeedsApproval
            ? 'Request deletion of this account?'
            : 'Delete this platform account?'
        }
        description={
          deleteNeedsApproval
            ? `A reviewer must approve deleting ${account.email} before it is removed.`
            : `${account.email} and its social accounts will be removed from the inventory.`
        }
        confirmLabel={deleteNeedsApproval ? 'Send request' : 'Delete account'}
        destructive
        pending={remove.isPending}
        onConfirm={() => remove.mutateAsync(account.id)}
      />
    </>
  )
}
