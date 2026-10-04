import { useState, type ComponentProps } from 'react'
import { EyeIcon, EyeOffIcon } from 'lucide-react'
import type { FieldPath, FieldValues } from 'react-hook-form'
import { Input } from '@/components/ui/input'
import {
  InputGroup,
  InputGroupAddon,
  InputGroupButton,
  InputGroupInput,
} from '@/components/ui/input-group'
import { FormField, type BaseFieldProps } from './FormField'

type InputProps = Pick<
  ComponentProps<'input'>,
  'type' | 'placeholder' | 'autoComplete' | 'autoFocus' | 'inputMode' | 'maxLength' | 'spellCheck'
>

export function TextField<TFieldValues extends FieldValues, TName extends FieldPath<TFieldValues>>({
  type = 'text',
  placeholder,
  autoComplete,
  autoFocus,
  inputMode,
  maxLength,
  spellCheck,
  ...props
}: BaseFieldProps<TFieldValues, TName> & InputProps) {
  return (
    <FormField
      {...props}
      render={({ field, aria }) => (
        <Input
          {...field}
          {...aria}
          value={field.value ?? ''}
          type={type}
          placeholder={placeholder}
          autoComplete={autoComplete}
          autoFocus={autoFocus}
          inputMode={inputMode}
          maxLength={maxLength}
          spellCheck={spellCheck}
        />
      )}
    />
  )
}

/** Password input with a show/hide toggle. */
export function PasswordField<
  TFieldValues extends FieldValues,
  TName extends FieldPath<TFieldValues>,
>({
  autoComplete = 'current-password',
  autoFocus,
  placeholder,
  ...props
}: BaseFieldProps<TFieldValues, TName> &
  Pick<InputProps, 'autoComplete' | 'autoFocus' | 'placeholder'>) {
  const [visible, setVisible] = useState(false)
  return (
    <FormField
      {...props}
      render={({ field, aria }) => (
        <InputGroup>
          <InputGroupInput
            {...field}
            {...aria}
            value={field.value ?? ''}
            type={visible ? 'text' : 'password'}
            autoComplete={autoComplete}
            autoFocus={autoFocus}
            placeholder={placeholder}
          />
          <InputGroupAddon align="inline-end">
            <InputGroupButton
              size="icon-xs"
              aria-label={visible ? 'Hide password' : 'Show password'}
              aria-pressed={visible}
              onClick={() => setVisible((value) => !value)}
            >
              {visible ? <EyeOffIcon /> : <EyeIcon />}
            </InputGroupButton>
          </InputGroupAddon>
        </InputGroup>
      )}
    />
  )
}
