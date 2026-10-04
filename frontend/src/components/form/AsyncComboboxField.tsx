import { useState } from 'react'
import { keepPreviousData, useQuery, type QueryKey } from '@tanstack/react-query'
import { CheckIcon, ChevronsUpDownIcon } from 'lucide-react'
import type { FieldPath, FieldValues } from 'react-hook-form'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Spinner } from '@/components/ui/spinner'
import { useDebouncedValue } from '@/hooks/use-debounced-value'
import { cn } from '@/lib/utils'
import { FormField, type BaseFieldProps } from './FormField'

export interface ComboboxOption {
  value: number
  label: string
  /** Secondary line, e.g. an email or username. */
  description?: string
}

interface AsyncComboboxFieldProps<
  TFieldValues extends FieldValues,
  TName extends FieldPath<TFieldValues>,
> extends BaseFieldProps<TFieldValues, TName> {
  /** Cache key prefix; the search term is appended. E.g. `['clients', 'options']`. */
  queryKey: QueryKey
  /** Loads options for a search term (server-side search, so the list can be any size). */
  fetchOptions: (search: string, signal: AbortSignal) => Promise<ComboboxOption[]>
  /** Label for the current value when editing (the option may not be in the first page). */
  initialOption?: ComboboxOption | null
  placeholder?: string
  searchPlaceholder?: string
}

/**
 * Searchable picker for related records (client, service, user...). The form value is the
 * record id (`number`) or `null`.
 *
 *   <AsyncComboboxField control={form.control} name="client_id" label="Client"
 *     queryKey={['clients', 'options']} fetchOptions={fetchClientOptions} />
 */
export function AsyncComboboxField<
  TFieldValues extends FieldValues,
  TName extends FieldPath<TFieldValues>,
>({
  queryKey,
  fetchOptions,
  initialOption = null,
  placeholder = 'Select…',
  searchPlaceholder = 'Search…',
  ...props
}: AsyncComboboxFieldProps<TFieldValues, TName>) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [selectedOption, setSelectedOption] = useState<ComboboxOption | null>(initialOption)
  const debouncedSearch = useDebouncedValue(search, 250)

  const options = useQuery({
    queryKey: [...queryKey, debouncedSearch],
    queryFn: ({ signal }) => fetchOptions(debouncedSearch, signal),
    enabled: open,
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  })

  return (
    <FormField
      {...props}
      render={({ field, aria }) => {
        const label =
          selectedOption && selectedOption.value === field.value
            ? selectedOption.label
            : options.data?.find((option) => option.value === field.value)?.label

        return (
          <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
              <Button
                {...aria}
                ref={field.ref}
                type="button"
                variant="outline"
                role="combobox"
                aria-expanded={open}
                disabled={field.disabled}
                onBlur={field.onBlur}
                className={cn(
                  'w-full justify-between font-normal',
                  !label && 'text-muted-foreground',
                )}
              >
                <span className="truncate">{label ?? placeholder}</span>
                <ChevronsUpDownIcon className="opacity-50" aria-hidden="true" />
              </Button>
            </PopoverTrigger>
            <PopoverContent
              className="w-(--radix-popover-trigger-width) min-w-64 p-0"
              align="start"
            >
              {/* The server already filtered the options, so cmdk's own filtering is off. */}
              <Command shouldFilter={false}>
                <CommandInput
                  placeholder={searchPlaceholder}
                  value={search}
                  onValueChange={setSearch}
                />
                <CommandList>
                  {options.isFetching && !options.data ? (
                    <div className="flex justify-center py-6">
                      <Spinner />
                    </div>
                  ) : (
                    <CommandEmpty>
                      {options.isError ? 'Could not load options.' : 'No results.'}
                    </CommandEmpty>
                  )}
                  <CommandGroup>
                    {options.data?.map((option) => (
                      <CommandItem
                        key={option.value}
                        value={String(option.value)}
                        onSelect={() => {
                          const next = option.value === field.value ? null : option
                          setSelectedOption(next)
                          field.onChange(next?.value ?? null)
                          setOpen(false)
                        }}
                      >
                        <CheckIcon
                          aria-hidden="true"
                          className={cn(
                            'size-4',
                            option.value === field.value ? 'opacity-100' : 'opacity-0',
                          )}
                        />
                        <span className="flex min-w-0 flex-col">
                          <span className="truncate">{option.label}</span>
                          {option.description ? (
                            <span className="text-muted-foreground truncate text-xs">
                              {option.description}
                            </span>
                          ) : null}
                        </span>
                      </CommandItem>
                    ))}
                  </CommandGroup>
                </CommandList>
              </Command>
            </PopoverContent>
          </Popover>
        )
      }}
    />
  )
}
