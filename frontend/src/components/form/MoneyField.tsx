import { useState } from 'react'
import type { FieldPath, FieldValues } from 'react-hook-form'
import {
  InputGroup,
  InputGroupAddon,
  InputGroupInput,
  InputGroupText,
} from '@/components/ui/input-group'
import { FormField, type BaseFieldProps } from './FormField'
import { centsToInput, parseMoneyInput } from './money'

/**
 * Money input. The user types dollars ("1234.50"); the form value is integer cents (`123450`)
 * or `null`, which is what the API expects in `*_cents` fields.
 */
export function MoneyField<
  TFieldValues extends FieldValues,
  TName extends FieldPath<TFieldValues>,
>({
  currency = 'USD',
  placeholder = '0.00',
  ...props
}: BaseFieldProps<TFieldValues, TName> & { currency?: string; placeholder?: string }) {
  return (
    <FormField
      {...props}
      render={({ field, aria }) => (
        <MoneyInput
          aria={aria}
          value={field.value as number | null}
          onChange={field.onChange}
          onBlur={field.onBlur}
          inputRef={field.ref}
          name={field.name}
          disabled={field.disabled}
          currency={currency}
          placeholder={placeholder}
        />
      )}
    />
  )
}

function MoneyInput({
  aria,
  value,
  onChange,
  onBlur,
  inputRef,
  name,
  disabled,
  currency,
  placeholder,
}: {
  aria: Record<string, unknown>
  value: number | null
  onChange: (cents: number | null) => void
  onBlur: () => void
  inputRef: (element: HTMLInputElement | null) => void
  name: string
  disabled?: boolean
  currency: string
  placeholder: string
}) {
  const [text, setText] = useState(() => centsToInput(value))

  // Follow outside changes (form reset, server data) without fighting the user's typing.
  // (Adjusting state during render is React's recommended alternative to an effect here.)
  const [lastValue, setLastValue] = useState(value)
  if (value !== lastValue) {
    setLastValue(value)
    if (parseMoneyInput(text) !== value) setText(centsToInput(value))
  }

  return (
    <InputGroup>
      <InputGroupAddon>
        <InputGroupText>{currency === 'USD' ? '$' : currency}</InputGroupText>
      </InputGroupAddon>
      <InputGroupInput
        {...aria}
        ref={inputRef}
        name={name}
        disabled={disabled}
        inputMode="decimal"
        autoComplete="off"
        placeholder={placeholder}
        className="tabular"
        value={text}
        onChange={(event) => {
          setText(event.target.value)
          onChange(parseMoneyInput(event.target.value))
        }}
        onBlur={() => {
          const cents = parseMoneyInput(text)
          if (cents !== null && !Number.isNaN(cents)) setText(centsToInput(cents))
          onBlur()
        }}
      />
      <InputGroupAddon align="inline-end">
        <InputGroupText>{currency}</InputGroupText>
      </InputGroupAddon>
    </InputGroup>
  )
}
