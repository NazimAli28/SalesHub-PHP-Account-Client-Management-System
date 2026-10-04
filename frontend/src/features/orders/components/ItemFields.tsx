import { useEffect, useRef } from 'react'
import type { FieldValues, Path, PathValue, UseFormReturn } from 'react-hook-form'
import { useFormState, useWatch } from 'react-hook-form'
import { AsyncComboboxField, FormField, MoneyField } from '@/components/form'
import { Input } from '@/components/ui/input'
import type { ComboboxOption } from '@/components/form'
import { baseCentsFor, fetchServiceOptions } from '../api'

interface ItemFieldsProps<T extends FieldValues> {
  form: UseFormReturn<T>
  /** Path of the item inside the form, with a trailing dot: `items.0.` or `` for a single item. */
  prefix: string
  currency?: string
  /** Label for the current service when editing (the option may not be in the first page). */
  initialService?: ComboboxOption | null
}

/**
 * Service + quantity + unit price for one order line. Choosing a service pre-fills the unit
 * price with the service's base price (the user can still change it).
 */
export function ItemFields<T extends FieldValues>({
  form,
  prefix,
  currency = 'USD',
  initialService = null,
}: ItemFieldsProps<T>) {
  const serviceName = `${prefix}service_id` as Path<T>
  const quantityName = `${prefix}quantity` as Path<T>
  const priceName = `${prefix}unit_price_cents` as Path<T>

  const serviceId = useWatch({ control: form.control, name: serviceName }) as number | null
  const formState = useFormState({ control: form.control, name: serviceName })
  const previous = useRef(serviceId)
  useEffect(() => {
    if (previous.current === serviceId) return
    previous.current = serviceId
    // Only a service the user picked pre-fills the price, not one loaded by a form reset.
    if (!form.getFieldState(serviceName, formState).isDirty) return
    const base = baseCentsFor(serviceId)
    if (base !== undefined) {
      form.setValue(priceName, base as PathValue<T, Path<T>>, { shouldDirty: true })
    }
  }, [serviceId, form, priceName, serviceName, formState])

  return (
    <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_6rem_10rem]">
      <AsyncComboboxField
        control={form.control}
        name={serviceName}
        label="Service"
        required
        queryKey={['services', 'options']}
        fetchOptions={fetchServiceOptions}
        initialOption={initialService}
        placeholder="Search services…"
        searchPlaceholder="Service name"
      />
      <FormField
        control={form.control}
        name={quantityName}
        label="Qty"
        required
        render={({ field, aria }) => (
          <Input
            {...aria}
            ref={field.ref}
            name={field.name}
            type="number"
            inputMode="numeric"
            min={1}
            max={1000}
            step={1}
            value={Number.isFinite(field.value) ? String(field.value) : ''}
            onBlur={field.onBlur}
            onChange={(event) => field.onChange(event.target.valueAsNumber)}
          />
        )}
      />
      <MoneyField
        control={form.control}
        name={priceName}
        label="Unit price"
        required
        currency={currency}
      />
    </div>
  )
}
