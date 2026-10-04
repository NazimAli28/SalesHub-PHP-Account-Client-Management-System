import { CheckCircle2Icon } from 'lucide-react'
import { BrandMark } from '@/app/layouts/BrandMark'
import { LoginForm } from '../components/LoginForm'

const HIGHLIGHTS = [
  'Leads, clients, orders and payments in one pipeline',
  'Maker-checker approvals for every sensitive change',
  'Role-based access for admins, support, team leads and sales',
]

export default function LoginPage() {
  return (
    <div className="grid min-h-svh lg:grid-cols-[1fr_1.1fr]">
      {/* Brand panel (large screens only). */}
      <aside className="bg-primary text-primary-foreground relative hidden overflow-hidden lg:flex lg:flex-col lg:justify-between lg:p-12">
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,oklch(1_0_0/0.18),transparent_45%),radial-gradient(circle_at_80%_90%,oklch(0.7_0.2_320/0.45),transparent_50%)]"
        />
        <div className="relative flex items-center gap-3">
          <BrandMark className="bg-white/15 bg-none shadow-none ring-1 ring-white/25" />
          <span className="text-lg font-semibold">SalesHub</span>
        </div>
        <div className="relative max-w-md space-y-6">
          <h2 className="text-3xl leading-tight font-semibold">The sales floor, organised.</h2>
          <ul className="text-primary-foreground/85 space-y-3">
            {HIGHLIGHTS.map((text) => (
              <li key={text} className="flex gap-3">
                <CheckCircle2Icon className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                <span>{text}</span>
              </li>
            ))}
          </ul>
        </div>
        <p className="text-primary-foreground/70 relative text-sm">
          © Nazim Ali. All rights reserved.
        </p>
      </aside>

      {/* Form panel. */}
      <main className="flex items-center justify-center px-4 py-12 sm:px-8">
        <div className="w-full max-w-sm space-y-8">
          <div className="space-y-2">
            <div className="flex items-center gap-2 lg:hidden">
              <BrandMark />
              <span className="font-semibold">SalesHub</span>
            </div>
            <h1 className="text-2xl font-semibold tracking-tight">Sign in to your workspace</h1>
            <p className="text-muted-foreground text-sm">
              Use the username or email your administrator gave you.
            </p>
          </div>
          <LoginForm />
        </div>
      </main>
    </div>
  )
}
