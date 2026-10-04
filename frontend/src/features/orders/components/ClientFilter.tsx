import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { CheckIcon, UserIcon, XIcon } from 'lucide-react'
import type { DataTableParams } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
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
import { fetchClientOptions, useClient } from '@/features/clients/api'
import { clientDisplayName } from '@/features/clients/types'
import { useDebouncedValue } from '@/hooks/use-debounced-value'

/** Single-client filter with server-side search (`?client=7` -> `filter[client]=7`). */
export function ClientFilter({ state }: { state: DataTableParams }) {
  const selectedId = state.params.filters.client?.[0]
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const debounced = useDebouncedValue(search, 250)

  const options = useQuery({
    queryKey: ['clients', 'options', debounced],
    queryFn: ({ signal }) => fetchClientOptions(debounced, signal),
    enabled: open,
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  })
  // The URL only holds the id: look up the name for the button label.
  const selected = useClient(Number(selectedId), { enabled: Boolean(selectedId) })

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant="outline" className="border-dashed">
          <UserIcon aria-hidden="true" />
          Client
          {selectedId ? (
            <Badge variant="secondary" className="max-w-32 truncate">
              {selected.data ? clientDisplayName(selected.data) : `#${selectedId}`}
            </Badge>
          ) : null}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-72 p-0" align="start">
        <Command shouldFilter={false}>
          <CommandInput
            placeholder="Search clients…"
            aria-label="Filter Client options"
            value={search}
            onValueChange={setSearch}
          />
          <CommandList>
            <CommandEmpty>
              {options.isFetching ? <Spinner className="mx-auto" /> : 'No clients found.'}
            </CommandEmpty>
            <CommandGroup>
              {options.data?.map((option) => (
                <CommandItem
                  key={option.value}
                  value={String(option.value)}
                  onSelect={() => {
                    state.setFilter('client', [String(option.value)])
                    setOpen(false)
                  }}
                >
                  <CheckIcon
                    aria-hidden="true"
                    className={
                      String(option.value) === selectedId
                        ? 'size-4 opacity-100'
                        : 'size-4 opacity-0'
                    }
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
            {selectedId ? (
              <CommandGroup>
                <CommandItem
                  onSelect={() => {
                    state.setFilter('client', [])
                    setOpen(false)
                  }}
                  className="justify-center text-center"
                >
                  <XIcon aria-hidden="true" />
                  Clear filter
                </CommandItem>
              </CommandGroup>
            ) : null}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
