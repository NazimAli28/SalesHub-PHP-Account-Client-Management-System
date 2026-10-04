import { useId, type ReactNode } from 'react'
import {
  Controller,
  type Control,
  type ControllerFieldState,
  type ControllerRenderProps,
  type FieldPath,
  type FieldValues,
} from 'react-hook-form'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { cn } from '@/lib/utils'

/** Props every field component shares. `name` is type-checked against the form's values. */
export interface BaseFieldProps<
  TFieldValues extends FieldValues,
  TName extends FieldPath<TFieldValues>,
> {
  control: Control<TFieldValues>
  name: TName
  label: ReactNode
  description?: ReactNode
  /** Adds a visual marker; the zod schema is what actually enforces it. */
  required?: boolean
  disabled?: boolean
  className?: string
}

/** What a field's control receives: RHF's field props plus the ids for labels and messages. */
export interface ControlProps<
  TFieldValues extends FieldValues,
  TName extends FieldPath<TFieldValues>,
> {
  field: ControllerRenderProps<TFieldValues, TName>
  fieldState: ControllerFieldState
  id: string
  /** Spread onto the focusable control. */
  aria: {
    id: string
    'aria-invalid': boolean
    'aria-describedby': string | undefined
    'aria-required': boolean | undefined
  }
}

interface FormFieldProps<
  TFieldValues extends FieldValues,
  TName extends FieldPath<TFieldValues>,
> extends BaseFieldProps<TFieldValues, TName> {
  orientation?: 'vertical' | 'horizontal'
  render: (props: ControlProps<TFieldValues, TName>) => ReactNode
}

/**
 * Wires one react-hook-form field to the shadcn Field layout: label, control, description and
 * the error message, with matching ids and aria attributes. The typed fields in this folder
 * (TextField, SelectField, ...) are thin wrappers around it; use it directly for one-off controls.
 */
export function FormField<TFieldValues extends FieldValues, TName extends FieldPath<TFieldValues>>({
  control,
  name,
  label,
  description,
  required,
  disabled,
  className,
  orientation = 'vertical',
  render,
}: FormFieldProps<TFieldValues, TName>) {
  const id = useId()
  const descriptionId = `${id}-description`
  const errorId = `${id}-error`

  return (
    <Controller
      control={control}
      name={name}
      disabled={disabled}
      render={({ field, fieldState }) => {
        const invalid = fieldState.invalid
        const describedBy = [description ? descriptionId : null, invalid ? errorId : null]
          .filter(Boolean)
          .join(' ')
        const labelNode = (
          <FieldLabel htmlFor={id}>
            {label}
            {required ? (
              <span aria-hidden="true" className="text-destructive">
                *
              </span>
            ) : null}
          </FieldLabel>
        )
        const control = render({
          field,
          fieldState,
          id,
          aria: {
            id,
            'aria-invalid': invalid,
            'aria-describedby': describedBy || undefined,
            'aria-required': required || undefined,
          },
        })

        return (
          <Field data-invalid={invalid} orientation={orientation} className={cn(className)}>
            {orientation === 'horizontal' ? (
              <>
                {control}
                {labelNode}
              </>
            ) : (
              <>
                {labelNode}
                {control}
              </>
            )}
            {description ? (
              <FieldDescription id={descriptionId}>{description}</FieldDescription>
            ) : null}
            {invalid ? <FieldError id={errorId} errors={[fieldState.error]} /> : null}
          </Field>
        )
      }}
    />
  )
}
