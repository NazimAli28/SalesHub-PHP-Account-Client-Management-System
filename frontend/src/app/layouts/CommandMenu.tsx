import { useEffect, useMemo, useState } from 'react'
import { SearchIcon } from 'lucide-react'
import { useNavigate } from 'react-router'
import { Button } from '@/components/ui/button'
import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { Kbd, KbdGroup } from '@/components/ui/kbd'
import { useAuth } from '@/features/auth/AuthProvider'
import { NAVIGATION, filterNavigation } from '../navigation'

/**
 * Ctrl+K / Cmd+K palette. For now it jumps between screens; record search (leads, clients,
 * orders) plugs in here in Phase 5.
 */
export function CommandMenu() {
  const [open, setOpen] = useState(false)
  const navigate = useNavigate()
  const { satisfies } = useAuth()
  const sections = useMemo(() => filterNavigation(NAVIGATION, satisfies), [satisfies])

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
        onOpenChange={setOpen}
        title="Search"
        description="Jump to a screen"
      >
        <CommandInput placeholder="Type a screen name…" />
        <CommandList>
          <CommandEmpty>No matching screens. Record search arrives soon.</CommandEmpty>
          {sections.map((section) => (
            <CommandGroup key={section.label} heading={section.label}>
              {section.items.map((item) => (
                <CommandItem
                  key={item.path}
                  value={item.label}
                  onSelect={() => {
                    setOpen(false)
                    void navigate(item.path)
                  }}
                >
                  <item.icon aria-hidden="true" />
                  {item.label}
                </CommandItem>
              ))}
            </CommandGroup>
          ))}
        </CommandList>
      </CommandDialog>
    </>
  )
}
