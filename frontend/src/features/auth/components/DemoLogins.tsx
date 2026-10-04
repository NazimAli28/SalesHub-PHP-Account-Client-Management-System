import { HeadsetIcon, ShieldCheckIcon, TargetIcon, UsersIcon, type LucideIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { FieldSeparator } from '@/components/ui/field'

/**
 * Seeded demo accounts (backend DemoSeeder, fictional people). Rendered only when
 * VITE_DEMO_MODE=true, which is meant for the public portfolio demo.
 */
const DEMO_PASSWORD = 'Demo@12345'

const DEMO_ACCOUNTS: { login: string; role: string; icon: LucideIcon }[] = [
  { login: 'admin', role: 'Admin', icon: ShieldCheckIcon },
  { login: 'support', role: 'Support', icon: HeadsetIcon },
  { login: 'tl', role: 'Team Lead', icon: UsersIcon },
  { login: 'agent1', role: 'Sales Executive', icon: TargetIcon },
]

export function DemoLogins({
  onPick,
  disabled,
}: {
  onPick: (credentials: { login: string; password: string }) => void
  disabled?: boolean
}) {
  return (
    <section aria-labelledby="demo-logins-title" className="space-y-4">
      <FieldSeparator>
        <span id="demo-logins-title">Explore the demo</span>
      </FieldSeparator>
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        {DEMO_ACCOUNTS.map(({ login, role, icon: Icon }) => (
          <Button
            key={login}
            type="button"
            variant="outline"
            className="h-auto justify-start py-2"
            disabled={disabled}
            onClick={() => onPick({ login, password: DEMO_PASSWORD })}
          >
            <Icon className="text-primary" aria-hidden="true" />
            <span className="flex flex-col items-start">
              <span className="text-muted-foreground text-xs">Sign in as</span>
              <span>{role}</span>
            </span>
          </Button>
        ))}
      </div>
      <p className="text-muted-foreground text-center text-xs">
        Demo data is fictional and resets regularly. Each role sees a different slice of the app.
      </p>
    </section>
  )
}
