import { CheckIcon, PlusCircleIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Separator } from '@/components/ui/separator'
import { Spinner } from '@/components/ui/spinner'
import { cn } from '@/lib/utils'
import type { DataTableParams } from './use-data-table-params'

export interface FilterOption {
  value: string
  label: string
  /** Optional leading visual, e.g. a status dot or avatar. */
  icon?: ReactNode
}

interface DataTableFacetedFilterProps {
  state: DataTableParams
  /** URL/API filter name, e.g. `stage` -> `?stage=` -> `filter[stage]=`. Must be in `filterKeys`. */
  filterKey: string
  title: string
  options: readonly FilterOption[]
  /** Allow picking several values (sent comma-separated). Default true. */
  multiple?: boolean
  isLoading?: boolean
}

/** Popover multi-select filter ("Stage: New, Engaged") that writes to the URL. */
export function DataTableFacetedFilter({
  state,
  filterKey,
  title,
  options,
  multiple = true,
  isLoading = false,
}: DataTableFacetedFilterProps) {
  const selected = new Set(state.params.filters[filterKey] ?? [])
  const selectedOptions = options.filter((option) => selected.has(option.value))

  const toggle = (value: string) => {
    const next = new Set(multiple ? selected : [])
    if (selected.has(value)) next.delete(value)
    else next.add(value)
    state.setFilter(filterKey, [...next])
  }

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" size="default" className="border-dashed">
          <PlusCircleIcon aria-hidden="true" />
          {title}
          {selected.size > 0 ? (
            <>
              <Separator orientation="vertical" className="mx-0.5 h-4" />
              <Badge variant="secondary" className="lg:hidden">
                {selected.size}
              </Badge>
              <span className="hidden gap-1 lg:flex">
                {selected.size > 2 ? (
                  <Badge variant="secondary">{selected.size} selected</Badge>
                ) : (
                  selectedOptions.map((option) => (
                    <Badge variant="secondary" key={option.value}>
                      {option.label}
                    </Badge>
                  ))
                )}
              </span>
            </>
          ) : null}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-56 p-0" align="start">
        <Command>
          <CommandInput placeholder={title} aria-label={`Filter ${title} options`} />
          <CommandList>
            <CommandEmpty>
              {isLoading ? <Spinner className="mx-auto" /> : 'No options found.'}
            </CommandEmpty>
            <CommandGroup>
              {options.map((option) => {
                const isSelected = selected.has(option.value)
                return (
                  <CommandItem
                    key={option.value}
                    value={`${option.label} ${option.value}`}
                    onSelect={() => toggle(option.value)}
                    aria-selected={isSelected}
                  >
                    <span
                      aria-hidden="true"
                      className={cn(
                        'border-input flex size-4 items-center justify-center rounded-[4px] border',
                        isSelected
                          ? 'border-primary bg-primary text-primary-foreground'
                          : '[&_svg]:invisible',
                      )}
                    >
                      <CheckIcon className="size-3" />
                    </span>
                    {option.icon}
                    <span className="truncate">{option.label}</span>
                  </CommandItem>
                )
              })}
            </CommandGroup>
            {selected.size > 0 ? (
              <>
                <CommandSeparator />
                <CommandGroup>
                  <CommandItem
                    onSelect={() => state.setFilter(filterKey, [])}
                    className="justify-center text-center"
                  >
                    Clear filter
                  </CommandItem>
                </CommandGroup>
              </>
            ) : null}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
