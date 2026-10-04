import { useState } from 'react'
import { EyeIcon, EyeOffIcon, PencilIcon, Trash2Icon } from 'lucide-react'
import { DataTableRowActions } from '@/components/data-table'
import { ConfirmDialog } from '@/components/layout/ConfirmDialog'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDeleteService, useSetServiceActive, type Service } from '../api'

interface ServiceRowActionsProps {
  service: Service
  onEdit: (service: Service) => void
}

export function ServiceRowActions({ service, onEdit }: ServiceRowActionsProps) {
  const { can } = useAuth()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const setActive = useSetServiceActive()
  const remove = useDeleteService()

  // The catalog is read-only without `services.manage`.
  if (!can('services.manage')) return null

  return (
    <>
      <DataTableRowActions label={`Actions for ${service.name}`}>
        <DropdownMenuItem onSelect={() => onEdit(service)}>
          <PencilIcon aria-hidden="true" />
          Edit
        </DropdownMenuItem>
        <DropdownMenuItem
          disabled={setActive.isPending}
          onSelect={() => setActive.mutate({ id: service.id, active: !service.is_active })}
        >
          {service.is_active ? <EyeOffIcon aria-hidden="true" /> : <EyeIcon aria-hidden="true" />}
          {service.is_active ? 'Deactivate' : 'Activate'}
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
        title="Delete this service?"
        description={`${service.name} will be removed from the catalog. Deactivate it instead to keep it on past orders.`}
        confirmLabel="Delete service"
        destructive
        pending={remove.isPending}
        onConfirm={() => remove.mutateAsync(service.id)}
      />
    </>
  )
}
