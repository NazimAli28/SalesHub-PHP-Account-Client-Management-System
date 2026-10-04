import { useId, type ReactNode } from 'react'
import type { FieldValues, SubmitHandler, UseFormReturn } from 'react-hook-form'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { FieldGroup } from '@/components/ui/field'
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { Spinner } from '@/components/ui/spinner'
import { FormRootError } from './FormRootError'

export interface FormContainerProps<TFieldValues extends FieldValues> {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: ReactNode
  description?: ReactNode
  form: UseFormReturn<TFieldValues>
  onSubmit: SubmitHandler<TFieldValues>
  /** Disables the buttons and shows a spinner (pass `mutation.isPending`). */
  pending?: boolean
  submitLabel?: string
  children: ReactNode
}

function FormBody<TFieldValues extends FieldValues>({
  id,
  form,
  onSubmit,
  children,
}: Pick<FormContainerProps<TFieldValues>, 'form' | 'onSubmit' | 'children'> & { id: string }) {
  return (
    <form id={id} noValidate onSubmit={form.handleSubmit(onSubmit)}>
      <FieldGroup>
        <FormRootError form={form} />
        {children}
      </FieldGroup>
    </form>
  )
}

function SubmitButton({
  formId,
  pending,
  label,
}: {
  formId: string
  pending?: boolean
  label: string
}) {
  return (
    <Button type="submit" form={formId} disabled={pending}>
      {pending ? <Spinner /> : null}
      {label}
    </Button>
  )
}

/**
 * Create/edit form in a centered dialog: best for short forms (up to ~6 fields).
 * The parent owns `open` and the form; reset the form when opening (see LeadFormSheet).
 */
export function FormDialog<TFieldValues extends FieldValues>({
  open,
  onOpenChange,
  title,
  description,
  form,
  onSubmit,
  pending,
  submitLabel = 'Save',
  children,
}: FormContainerProps<TFieldValues>) {
  const formId = useId()
  return (
    <Dialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {description ? <DialogDescription>{description}</DialogDescription> : null}
        </DialogHeader>
        <FormBody id={formId} form={form} onSubmit={onSubmit}>
          {children}
        </FormBody>
        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline" disabled={pending}>
              Cancel
            </Button>
          </DialogClose>
          <SubmitButton formId={formId} pending={pending} label={submitLabel} />
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/**
 * Create/edit form in a side sheet: for longer forms, and when the user benefits from still
 * seeing the list behind it.
 */
export function FormSheet<TFieldValues extends FieldValues>({
  open,
  onOpenChange,
  title,
  description,
  form,
  onSubmit,
  pending,
  submitLabel = 'Save',
  children,
}: FormContainerProps<TFieldValues>) {
  const formId = useId()
  return (
    <Sheet open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <SheetContent className="flex w-full flex-col gap-0 sm:max-w-lg">
        <SheetHeader className="border-b">
          <SheetTitle>{title}</SheetTitle>
          {description ? <SheetDescription>{description}</SheetDescription> : null}
        </SheetHeader>
        <div className="flex-1 overflow-y-auto p-4">
          <FormBody id={formId} form={form} onSubmit={onSubmit}>
            {children}
          </FormBody>
        </div>
        <SheetFooter className="flex-row justify-end border-t">
          <SheetClose asChild>
            <Button variant="outline" disabled={pending}>
              Cancel
            </Button>
          </SheetClose>
          <SubmitButton formId={formId} pending={pending} label={submitLabel} />
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
