import { useEffect, useRef, useState } from 'react'
import { SearchIcon, XIcon } from 'lucide-react'
import {
  InputGroup,
  InputGroupAddon,
  InputGroupButton,
  InputGroupInput,
} from '@/components/ui/input-group'
import { useDebouncedValue } from '@/hooks/use-debounced-value'
import type { DataTableParams } from './use-data-table-params'

interface DataTableSearchProps {
  state: DataTableParams
  placeholder?: string
  /** Accessible name; defaults to the placeholder. */
  label?: string
  delay?: number
}

/** Search box that writes `?q=` to the URL 300 ms after the user stops typing. */
export function DataTableSearch({
  state,
  placeholder = 'Search…',
  label,
  delay = 300,
}: DataTableSearchProps) {
  const urlValue = state.params.search
  const [value, setValue] = useState(urlValue)
  const debounced = useDebouncedValue(value, delay)
  const lastPushed = useRef(urlValue)

  // URL -> input (back/forward, "Clear filters").
  useEffect(() => {
    if (urlValue !== lastPushed.current) {
      lastPushed.current = urlValue
      setValue(urlValue)
    }
  }, [urlValue])

  // Input -> URL, debounced.
  const { setSearch } = state
  useEffect(() => {
    if (debounced.trim() !== lastPushed.current) {
      lastPushed.current = debounced.trim()
      setSearch(debounced)
    }
  }, [debounced, setSearch])

  return (
    <InputGroup className="w-full sm:w-72">
      <InputGroupAddon>
        <SearchIcon aria-hidden="true" />
      </InputGroupAddon>
      <InputGroupInput
        type="search"
        value={value}
        onChange={(event) => setValue(event.target.value)}
        placeholder={placeholder}
        aria-label={label ?? placeholder}
      />
      {value ? (
        <InputGroupAddon align="inline-end">
          <InputGroupButton size="icon-xs" aria-label="Clear search" onClick={() => setValue('')}>
            <XIcon />
          </InputGroupButton>
        </InputGroupAddon>
      ) : null}
    </InputGroup>
  )
}
