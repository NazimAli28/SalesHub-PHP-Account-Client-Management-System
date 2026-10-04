import type { FieldPath, FieldValues } from 'react-hook-form'
import { REGEXP_ONLY_DIGITS } from 'input-otp'
import { FormField, type BaseFieldProps } from '@/components/form'
import { InputOTP, InputOTPGroup, InputOTPSeparator, InputOTPSlot } from '@/components/ui/input-otp'

/** A 6-digit one-time code (authenticator app), as six boxes backed by one real input. */
export function OtpCodeField<
  TFieldValues extends FieldValues,
  TName extends FieldPath<TFieldValues>,
>({
  autoFocus,
  onComplete,
  ...props
}: BaseFieldProps<TFieldValues, TName> & {
  autoFocus?: boolean
  /** Called with the code once all six digits are in. */
  onComplete?: (code: string) => void
}) {
  return (
    <FormField
      {...props}
      render={({ field, aria }) => (
        <InputOTP
          {...aria}
          ref={field.ref}
          name={field.name}
          value={(field.value as string | undefined) ?? ''}
          onChange={field.onChange}
          onBlur={field.onBlur}
          onComplete={onComplete}
          disabled={field.disabled}
          maxLength={6}
          pattern={REGEXP_ONLY_DIGITS}
          inputMode="numeric"
          autoComplete="one-time-code"
          autoFocus={autoFocus}
        >
          <InputOTPGroup>
            <InputOTPSlot index={0} aria-invalid={aria['aria-invalid']} />
            <InputOTPSlot index={1} aria-invalid={aria['aria-invalid']} />
            <InputOTPSlot index={2} aria-invalid={aria['aria-invalid']} />
          </InputOTPGroup>
          <InputOTPSeparator />
          <InputOTPGroup>
            <InputOTPSlot index={3} aria-invalid={aria['aria-invalid']} />
            <InputOTPSlot index={4} aria-invalid={aria['aria-invalid']} />
            <InputOTPSlot index={5} aria-invalid={aria['aria-invalid']} />
          </InputOTPGroup>
        </InputOTP>
      )}
    />
  )
}
