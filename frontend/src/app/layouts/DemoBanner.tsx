import { useState } from 'react'
import { InfoIcon, RotateCcwIcon, XIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { accountSecurity } from '@/features/auth/api'
import { useAuth } from '@/features/auth/AuthProvider'
import { isStaticDemo, REPOSITORY_URL, resetStaticDemoData } from '@/lib/static-demo'

const STORAGE_KEY = 'saleshub.demo-banner-dismissed'

function wasDismissed(): boolean {
  try {
    return window.sessionStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

/**
 * Slim notice for the public demo (the API reports `demo_mode`). Dismissible per browser session.
 * The static browser demo (GitHub Pages) explains that the API runs in the browser and offers a
 * reset of this tab's changes.
 */
export function DemoBanner({ staticDemo = isStaticDemo }: { staticDemo?: boolean }) {
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
      {staticDemo ? (
        <p className="min-w-0 flex-1">
          <span className="font-medium">Browser demo:</span> the API runs in your browser with
          sample data. Changes stay in this tab.{' '}
          <a
            href={REPOSITORY_URL}
            target="_blank"
            rel="noreferrer"
            className="text-primary font-medium underline-offset-4 hover:underline"
          >
            Full Laravel stack on GitHub
          </a>
        </p>
      ) : (
        <p className="min-w-0 flex-1">
          <span className="font-medium">Demo mode:</span> shared demo accounts, data resets every
          hour.
        </p>
      )}
      {staticDemo ? (
        <Button type="button" variant="ghost" size="sm" onClick={resetStaticDemoData}>
          <RotateCcwIcon aria-hidden="true" />
          Reset demo data
        </Button>
      ) : null}
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
