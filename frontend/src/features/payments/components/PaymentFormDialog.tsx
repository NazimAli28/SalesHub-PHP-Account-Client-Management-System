import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import {
  DateField,
  FormDialog,
  MoneyField,
  SelectField,
  TextareaField,
  TextField,
} from '@/components/form'
import { pickChanged } from '@/features/clients/schemas'
import { useAuth } from '@/features/auth/AuthProvider'
import { useCreatePayment, useUpdatePayment } from '../api'
import {
  paymentFormDefaults,
  paymentFormSchema,
  toPaymentPayload,
  type PaymentFormValues,
} from '../schemas'
import { PAYMENT_METHOD_OPTIONS, type Payment } from '../types'

const STATUS_OPTIONS = [
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'void', label: 'Void' },
]

interface PaymentFormDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  orderId: number
  /** Edit this installment; omit to schedule a new one. */
  payment?: Payment | null
  /** Amount pre-filled for a new installment (the unscheduled remainder). */
  suggestedCents?: number | null
  currency?: string
}

/**
 * Schedule or edit an installment. Backend rules (e.g. "scheduled total exceeds the order
 * total") come back as 422 and are shown next to the amount.
 */
export function PaymentFormDialog({
  open,
  onOpenChange,
  orderId,
  payment,
  suggestedCents = null,
  currency = 'USD',
}: PaymentFormDialogProps) {
  const isEdit = Boolean(payment)
  const { can } = useAuth()
  const needsApproval = isEdit && !can('payments.update')

  const form = useForm<PaymentFormValues>({
    resolver: zodResolver(paymentFormSchema),
    defaultValues: paymentFormDefaults(payment, suggestedCents),
  })

  useEffect(() => {
    if (open) form.reset(paymentFormDefaults(payment, suggestedCents))
  }, [open, payment, suggestedCents, form])

  const close = () => onOpenChange(false)
  const create = useCreatePayment(orderId, { form, onSuccess: close })
  const update = useUpdatePayment({ form, onSuccess: close })

  const onSubmit = (values: PaymentFormValues) => {
    const payload = toPaymentPayload(values, isEdit)
    if (payment) {
      update.mutate({ id: payment.id, payload: pickChanged(payload, form.formState.dirtyFields) })
    } else {
      create.mutate(payload)
    }
  }

  return (
    <FormDialog
      open={open}
      onOpenChange={onOpenChange}
      title={isEdit ? `Edit installment ${payment?.sequence}` : 'Add installment'}
      description={
        needsApproval
          ? 'Your changes will be sent to a reviewer before they apply.'
          : 'The scheduled installments cannot add up to more than the order total.'
      }
      form={form}
      onSubmit={onSubmit}
      pending={create.isPending || update.isPending}
      submitLabel={
        needsApproval ? 'Send for approval' : isEdit ? 'Save changes' : 'Add installment'
      }
    >
      <div className="grid gap-5 sm:grid-cols-2">
        <MoneyField
          control={form.control}
          name="amount_cents"
          label="Amount"
          required
          currency={currency}
        />
        <DateField
          control={form.control}
          name="due_date"
          label="Due date"
          required
          clearable={false}
        />
      </div>
      {isEdit ? (
        <SelectField
          control={form.control}
          name="status"
          label="Status"
          options={STATUS_OPTIONS}
          description="Use Mark paid to record a payment."
        />
      ) : null}
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
