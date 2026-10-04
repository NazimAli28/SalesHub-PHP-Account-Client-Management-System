import type { FieldPath, FieldValues } from 'react-hook-form'
import { Textarea } from '@/components/ui/textarea'
import { FormField, type BaseFieldProps } from './FormField'

export function TextareaField<
  TFieldValues extends FieldValues,
  TName extends FieldPath<TFieldValues>,
>({
  placeholder,
  rows = 4,
  maxLength,
  ...props
}: BaseFieldProps<TFieldValues, TName> & {
  placeholder?: string
  rows?: number
  maxLength?: number
}) {
  return (
    <FormField
      {...props}
      render={({ field, aria }) => (
        <Textarea
          {...field}
          {...aria}
          value={field.value ?? ''}
          placeholder={placeholder}
          rows={rows}
          maxLength={maxLength}
        />
      )}
    />
  )
}
