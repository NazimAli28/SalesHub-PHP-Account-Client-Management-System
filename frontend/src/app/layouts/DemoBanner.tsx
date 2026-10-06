import { useState } from 'react'
import { InfoIcon, XIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { accountSecurity } from '@/features/auth/api'
import { useAuth } from '@/features/auth/AuthProvider'

const STORAGE_KEY = 'saleshub.demo-banner-dismissed'

function wasDismissed(): boolean {
  try {
    return window.sessionStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

/** Slim notice for the public demo (the API reports `demo_mode`). Dismissible per browser session. */
export function DemoBanner() {
  const { user } = useAuth()
  const [dismissed, setDismissed] = useState(wasDismissed)

  if (!user || !accountSecurity(user).demoMode || dismissed) return null

  function dismiss() {
    setDismissed(true)
    try {
      window.sessionStorage.setItem(STORAGE_KEY, '1')
    } catch {
      // Storage can be blocked; the banner then simply returns on the next visit.
    }
  }

  return (
    <aside
      aria-label="Demo mode"
      className="bg-primary/10 text-foreground border-primary/20 flex items-center gap-2 border-b px-4 py-1.5 text-sm"
    >
      <InfoIcon className="text-primary size-4 shrink-0" aria-hidden="true" />
      <p className="min-w-0 flex-1">
        <span className="font-medium">Demo mode:</span> shared demo accounts, data resets every
        hour.
      </p>
      <Button
        type="button"
        variant="ghost"
        size="icon-sm"
        aria-label="Dismiss demo notice"
        onClick={dismiss}
      >
        <XIcon aria-hidden="true" />
      </Button>
    </aside>
  )
}
