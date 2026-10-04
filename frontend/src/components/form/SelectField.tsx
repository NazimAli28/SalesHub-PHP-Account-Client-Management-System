import type { FieldPath, FieldValues } from 'react-hook-form'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { EnumOption } from '@/lib/enums'
import { FormField, type BaseFieldProps } from './FormField'

/** Sentinel for "no value": Radix Select cannot use an empty string as an item value. */
const NONE = '__none__'

/**
 * Select for small, fixed option lists (enums). For long or server-side lists use
 * AsyncComboboxField. The form value is the option's `value` string, or `null` when `clearable`.
 */
export function SelectField<
  TFieldValues extends FieldValues,
  TName extends FieldPath<TFieldValues>,
>({
  options,
  placeholder = 'Select…',
  clearable = false,
  clearLabel = 'None',
  ...props
}: BaseFieldProps<TFieldValues, TName> & {
  options: readonly EnumOption[]
  placeholder?: string
  /** Adds a "None" item that sets the value to `null`. */
  clearable?: boolean
  clearLabel?: string
}) {
  return (
    <FormField
      {...props}
      render={({ field, aria }) => (
        <Select
          name={field.name}
          value={field.value ?? (clearable ? NONE : '')}
          onValueChange={(value) => field.onChange(value === NONE ? null : value)}
          disabled={field.disabled}
        >
          <SelectTrigger {...aria} ref={field.ref} onBlur={field.onBlur} className="w-full">
            <SelectValue placeholder={placeholder} />
          </SelectTrigger>
          <SelectContent>
            {clearable ? (
              <SelectItem value={NONE} className="text-muted-foreground">
                {clearLabel}
              </SelectItem>
            ) : null}
            {options.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}
    />
  )
}
