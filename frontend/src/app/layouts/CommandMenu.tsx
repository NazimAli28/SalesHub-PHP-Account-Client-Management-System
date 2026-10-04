import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  KeyRoundIcon,
  ReceiptTextIcon,
  SearchIcon,
  TargetIcon,
  UsersIcon,
  type LucideIcon,
} from 'lucide-react'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import type { Envelope } from '@/api/types'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandDialog,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { Kbd, KbdGroup } from '@/components/ui/kbd'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/hooks/use-debounced-value'
import { NAVIGATION, filterNavigation } from '../navigation'

export interface SearchHit {
  type: 'client' | 'lead' | 'order' | 'platform_account' | (string & {})
  id: number
  title: string
  subtitle: string | null
  /** SPA path, e.g. `/clients/12`. */
  url: string
}

export interface SearchGroup {
  key: string
  label: string
  hits: SearchHit[]
}

const MIN_QUERY_LENGTH = 2
const DEBOUNCE_MS = 250

const HIT_ICONS: Record<string, LucideIcon> = {
  client: UsersIcon,
  lead: TargetIcon,
  order: ReceiptTextIcon,
  platform_account: KeyRoundIcon,
}

function useRecordSearch(term: string) {
  const enabled = term.length >= MIN_QUERY_LENGTH
  return useQuery({
    queryKey: ['search', term],
    queryFn: ({ signal }) =>
      api.get<Envelope<SearchGroup[]>>('/search', { query: { q: term }, signal }),
    select: (response) => response.data,
    enabled,
    staleTime: 30_000,
  })
}

/**
 * Ctrl+K / Cmd+K palette. Typing two or more characters searches records (clients, leads, orders,
 * platform accounts) through `GET /api/search`; screens always stay available below the results.
 */
export function CommandMenu() {
  const [open, setOpen] = useState(false)
  const [input, setInput] = useState('')
  const navigate = useNavigate()
  const { satisfies } = useAuth()
  const sections = useMemo(() => filterNavigation(NAVIGATION, satisfies), [satisfies])

  const trimmed = input.trim()
  const term = useDebouncedValue(trimmed, DEBOUNCE_MS)
  const search = useRecordSearch(term)
  const searching = trimmed.length >= MIN_QUERY_LENGTH
  // The debounce lags behind typing: results (or the spinner) belong to `term`, not to `input`.
  const waiting = searching && (term !== trimmed || search.isFetching)

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault()
        setOpen((value) => !value)
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [])

  const onOpenChange = (next: boolean) => {
    setOpen(next)
    if (!next) setInput('')
  }

  const go = (path: string) => {
    onOpenChange(false)
    void navigate(path)
  }

  // Screens are filtered here because the palette runs with cmdk filtering off (records come from the server).
  const needle = trimmed.toLowerCase()
  const visibleSections = sections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => item.label.toLowerCase().includes(needle)),
    }))
    .filter((section) => section.items.length > 0)

  const groups = searching && term === trimmed ? (search.data ?? []) : []
  const hitCount = groups.reduce((sum, group) => sum + group.hits.length, 0)

  return (
    <>
      <Button
        variant="outline"
        onClick={() => setOpen(true)}
        className="text-muted-foreground h-8 w-9 justify-start gap-2 px-2 sm:w-56 sm:px-3"
        aria-label="Search (Ctrl+K)"
        aria-keyshortcuts="Control+K Meta+K"
      >
        <SearchIcon aria-hidden="true" />
        <span className="hidden flex-1 text-left font-normal sm:inline">Search…</span>
        <KbdGroup className="hidden sm:inline-flex">
          <Kbd>Ctrl</Kbd>
          <Kbd>K</Kbd>
        </KbdGroup>
      </Button>

      <CommandDialog
        open={open}
        onOpenChange={onOpenChange}
        title="Search"
        description="Search records or jump to a screen"
      >
        <Command shouldFilter={false} className="rounded-none p-0">
          <CommandInput
            value={input}
            onValueChange={setInput}
            placeholder="Search clients, leads, orders or jump to a screen…"
          />
          <CommandList>
            {waiting ? (
              <p role="status" className="text-muted-foreground px-3 py-2 text-sm">
                Searching…
              </p>
            ) : null}
            {search.isError && searching && !waiting ? (
              <p role="alert" className="text-destructive px-3 py-2 text-sm">
                Search is unavailable right now.
              </p>
            ) : null}
            {groups
              .filter((group) => group.hits.length > 0)
              .map((group) => (
                <CommandGroup key={group.key} heading={group.label}>
                  {group.hits.map((hit) => {
                    const Icon = HIT_ICONS[hit.type] ?? SearchIcon
                    return (
                      <CommandItem
                        key={`${hit.type}-${hit.id}`}
                        value={`${hit.type}-${hit.id}`}
                        onSelect={() => go(hit.url)}
                      >
                        <Icon aria-hidden="true" />
                        <span className="min-w-0 flex-1 truncate">{hit.title}</span>
                        {hit.subtitle ? (
                          <span className="text-muted-foreground truncate text-xs">
                            {hit.subtitle}
                          </span>
                        ) : null}
                      </CommandItem>
                    )
                  })}
                </CommandGroup>
              ))}
            {searching && !waiting && !search.isError && hitCount === 0 ? (
              <p className="text-muted-foreground px-3 py-2 text-sm">
                No matching records for &ldquo;{trimmed}&rdquo;.
              </p>
            ) : null}
            {visibleSections.map((section) => (
              <CommandGroup key={section.label} heading={section.label}>
                {section.items.map((item) => (
                  <CommandItem key={item.path} value={item.path} onSelect={() => go(item.path)}>
                    <item.icon aria-hidden="true" />
                    {item.label}
                  </CommandItem>
                ))}
              </CommandGroup>
            ))}
          </CommandList>
        </Command>
      </CommandDialog>
    </>
  )
}
