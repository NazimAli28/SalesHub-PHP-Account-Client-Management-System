import { useState } from 'react'
import { CalendarIcon, XIcon } from 'lucide-react'
import type { FieldPath, FieldValues } from 'react-hook-form'
import { Button } from '@/components/ui/button'
import { Calendar } from '@/components/ui/calendar'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { formatDate, parseDate, toIsoDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import { FormField, type BaseFieldProps } from './FormField'

/**
 * Date picker. The form value is a `YYYY-MM-DD` string (what the API expects) or `null`.
 * `maxDate` / `minDate` only limit the calendar; validate the same rule in the zod schema.
 */
export function DateField<TFieldValues extends FieldValues, TName extends FieldPath<TFieldValues>>({
  placeholder = 'Pick a date',
  clearable = true,
  minDate,
  maxDate,
  ...props
}: BaseFieldProps<TFieldValues, TName> & {
  placeholder?: string
  clearable?: boolean
  minDate?: Date
  maxDate?: Date
}) {
  const [open, setOpen] = useState(false)

  return (
    <FormField
      {...props}
      render={({ field, aria }) => {
        const selected = parseDate(field.value as string | null) ?? undefined
        return (
          <div className="flex gap-1">
            <Popover open={open} onOpenChange={setOpen}>
              <PopoverTrigger asChild>
                <Button
                  {...aria}
                  ref={field.ref}
                  type="button"
                  variant="outline"
                  disabled={field.disabled}
                  onBlur={field.onBlur}
                  className={cn(
                    'flex-1 justify-start font-normal',
                    !selected && 'text-muted-foreground',
                  )}
                >
                  <CalendarIcon aria-hidden="true" />
                  {selected ? formatDate(field.value as string) : placeholder}
                </Button>
              </PopoverTrigger>
              <PopoverContent className="w-auto p-0" align="start">
                <Calendar
                  mode="single"
                  selected={selected}
                  defaultMonth={selected}
                  disabled={[
                    ...(minDate ? [{ before: minDate }] : []),
                    ...(maxDate ? [{ after: maxDate }] : []),
                  ]}
                  onSelect={(date) => {
                    field.onChange(date ? toIsoDate(date) : null)
                    setOpen(false)
                  }}
                  autoFocus
                />
              </PopoverContent>
            </Popover>
            {clearable && selected && !field.disabled ? (
              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label="Clear date"
                onClick={() => field.onChange(null)}
              >
                <XIcon />
              </Button>
            ) : null}
          </div>
        )
      }}
    />
  )
}
