import { useState } from 'react'
import { KeyRoundIcon, PencilIcon, Trash2Icon } from 'lucide-react'
import { DataTableRowActions } from '@/components/data-table'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDeleteSocialAccount } from '../api'
import type { SocialAccount } from '../types'
import { RevealSocialPasswordDialog } from './RevealSocialPasswordDialog'

interface SocialAccountRowActionsProps {
  account: SocialAccount
  onEdit: (account: SocialAccount) => void
}

/** Row menu, each item gated by permission. Edits/deletes by request-change users are queued (202). */
export function SocialAccountRowActions({ account, onEdit }: SocialAccountRowActionsProps) {
  const { can, canAny } = useAuth()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [revealing, setRevealing] = useState(false)
  const remove = useDeleteSocialAccount()

  const canEdit = canAny(['social-accounts.update', 'social-accounts.request-change'])
  const canDelete = canAny(['social-accounts.delete', 'social-accounts.request-change'])
  const canReveal = can('social-accounts.reveal-credentials')
  const deleteNeedsApproval = !can('social-accounts.delete')
  const hasPendingChange = Boolean(account.pending_change)
  const name = `${account.platform.label} @${account.username}`

  if (!canEdit && !canDelete && !canReveal) return null

  return (
    <>
      <DataTableRowActions label={`Actions for ${name}`}>
        {canReveal ? (
          <DropdownMenuItem onSelect={() => setRevealing(true)}>
            <KeyRoundIcon aria-hidden="true" />
            Reveal password
          </DropdownMenuItem>
        ) : null}
        {canEdit ? (
          <DropdownMenuItem disabled={hasPendingChange} onSelect={() => onEdit(account)}>
            <PencilIcon aria-hidden="true" />
            {can('social-accounts.update') ? 'Edit' : 'Request edit'}
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

      {canReveal ? (
        <RevealSocialPasswordDialog
          account={account}
          open={revealing}
          onOpenChange={setRevealing}
        />
      ) : null}

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={deleteNeedsApproval ? 'Request deletion of this account?' : 'Delete this account?'}
        description={
          deleteNeedsApproval
            ? `A reviewer must approve deleting ${name} before it is removed.`
            : `${name} will be removed from the inventory.`
        }
        confirmLabel={deleteNeedsApproval ? 'Send request' : 'Delete account'}
        destructive
        pending={remove.isPending}
        onConfirm={() => remove.mutateAsync(account.id)}
      />
    </>
  )
}
