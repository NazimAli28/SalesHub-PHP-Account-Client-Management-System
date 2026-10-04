import type { FieldPath, FieldValues } from 'react-hook-form'
import { Checkbox } from '@/components/ui/checkbox'
import { FormField, type BaseFieldProps } from './FormField'

/** Checkbox with its label on the right. The form value is a boolean. */
export function CheckboxField<
  TFieldValues extends FieldValues,
  TName extends FieldPath<TFieldValues>,
>(props: BaseFieldProps<TFieldValues, TName>) {
  return (
    <FormField
      {...props}
      orientation="horizontal"
      render={({ field, aria }) => (
        <Checkbox
          {...aria}
          ref={field.ref}
          name={field.name}
          checked={field.value === true}
          onCheckedChange={(checked) => field.onChange(checked === true)}
          onBlur={field.onBlur}
          disabled={field.disabled}
        />
      )}
    />
  )
}
