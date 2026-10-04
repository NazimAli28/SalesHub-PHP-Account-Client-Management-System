import { useState } from 'react'
import { PencilIcon, Trash2Icon, UserCheckIcon, UserXIcon } from 'lucide-react'
import type { User } from '@/api/types'
import { DataTableRowActions } from '@/components/data-table'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDeleteUser, useSetUserActive } from '../api'
import { canTouchUser, isSelf } from '../permissions'

interface UserRowActionsProps {
  user: User
  onEdit: (user: User) => void
}

/**
 * Row menu. Mirrors UserPolicy: no one can deactivate or delete themselves, and admin/support
 * accounts are off limits without `users.manage-privileged`. (The "last active admin" rule is
 * enforced by the API: its 403 is shown as a toast.)
 */
export function UserRowActions({ user, onEdit }: UserRowActionsProps) {
  const auth = useAuth()
  const { can } = auth
  const [confirm, setConfirm] = useState<'toggle' | 'delete' | null>(null)
  const setActive = useSetUserActive()
  const remove = useDeleteUser()

  const self = isSelf(auth.user, user)
  const touchable = canTouchUser(auth, user)
  const canEdit = touchable && can('users.update')
  const canToggle = touchable && !self && can('users.deactivate')
  const canDelete = touchable && !self && can('users.delete')

  if (!canEdit && !canToggle && !canDelete) {
    return <span className="sr-only">No actions available</span>
  }

  const deactivating = user.is_active

  return (
    <>
      <DataTableRowActions label={`Actions for ${user.name}`}>
        {canEdit ? (
          <DropdownMenuItem onSelect={() => onEdit(user)}>
            <PencilIcon aria-hidden="true" />
            Edit
          </DropdownMenuItem>
        ) : null}
        {canToggle ? (
          <DropdownMenuItem onSelect={() => setConfirm('toggle')}>
            {deactivating ? <UserXIcon aria-hidden="true" /> : <UserCheckIcon aria-hidden="true" />}
            {deactivating ? 'Deactivate' : 'Activate'}
          </DropdownMenuItem>
        ) : null}
        {canDelete ? (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem variant="destructive" onSelect={() => setConfirm('delete')}>
              <Trash2Icon aria-hidden="true" />
              Delete
            </DropdownMenuItem>
          </>
        ) : null}
      </DataTableRowActions>

      <ConfirmDialog
        open={confirm === 'toggle'}
        onOpenChange={(open) => !open && setConfirm(null)}
        title={deactivating ? `Deactivate ${user.name}?` : `Activate ${user.name}?`}
        description={
          deactivating
            ? 'They will be signed out everywhere and cannot sign in until the account is activated again.'
            : 'They will be able to sign in again.'
        }
        confirmLabel={deactivating ? 'Deactivate user' : 'Activate user'}
        destructive={deactivating}
        pending={setActive.isPending}
        onConfirm={() => setActive.mutateAsync({ id: user.id, active: !deactivating })}
      />
      <ConfirmDialog
        open={confirm === 'delete'}
        onOpenChange={(open) => !open && setConfirm(null)}
        title={`Delete ${user.name}?`}
        description="The account is removed and they are signed out everywhere. Records they created are kept."
        confirmLabel="Delete user"
        destructive
        pending={remove.isPending}
        onConfirm={() => remove.mutateAsync(user.id)}
      />
    </>
  )
}
