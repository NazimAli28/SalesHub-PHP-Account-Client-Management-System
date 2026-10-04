import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { DateField, FormDialog, SelectField, TextareaField, TextField } from '@/components/form'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatMoney } from '@/lib/format'
import { useMarkPaymentPaid } from '../api'
import {
  markPaidDefaults,
  markPaidSchema,
  toMarkPaidPayload,
  type MarkPaidFormValues,
} from '../schemas'
import { PAYMENT_METHOD_OPTIONS, type Payment } from '../types'

interface MarkPaidDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  payment: Payment | null
}

/**
 * Records a payment. Roles that can update payments record it directly (200); a sales
 * executive's request is queued for approval (202), which the mutation reports as a toast.
 */
export function MarkPaidDialog({ open, onOpenChange, payment }: MarkPaidDialogProps) {
  const [today] = useState(() => new Date())
  const { can } = useAuth()
  const needsApproval = !can('payments.update')

  const form = useForm<MarkPaidFormValues>({
    resolver: zodResolver(markPaidSchema),
    defaultValues: markPaidDefaults(payment),
  })

  useEffect(() => {
    if (open) form.reset(markPaidDefaults(payment))
  }, [open, payment, form])

  const mutation = useMarkPaymentPaid({ form, onSuccess: () => onOpenChange(false) })

  return (
    <FormDialog
      open={open}
      onOpenChange={onOpenChange}
      title="Mark as paid"
      description={
        needsApproval
          ? `A reviewer must approve recording ${formatMoney(payment?.amount)} before it counts.`
          : `Record ${formatMoney(payment?.amount)} for installment ${payment?.sequence ?? ''}.`
      }
      form={form}
      onSubmit={(values) => {
        if (payment) mutation.mutate({ id: payment.id, payload: toMarkPaidPayload(values) })
      }}
      pending={mutation.isPending}
      submitLabel={needsApproval ? 'Send for approval' : 'Mark as paid'}
    >
      <DateField
        control={form.control}
        name="paid_at"
        label="Paid on"
        maxDate={today}
        clearable={false}
      />
      <div className="grid gap-5 sm:grid-cols-2">
        <SelectField
          control={form.control}
          name="method"
          label="Method"
          clearable
          clearLabel="Not set"
          options={PAYMENT_METHOD_OPTIONS}
          placeholder="Not set"
        />
        <TextField control={form.control} name="reference" label="Reference" maxLength={100} />
      </div>
      <TextareaField control={form.control} name="notes" label="Notes" rows={2} maxLength={2000} />
      {needsApproval ? (
        <TextareaField
          control={form.control}
          name="reason"
          label="Reason for the change"
          rows={2}
          maxLength={500}
        />
      ) : null}
    </FormDialog>
  )
}
