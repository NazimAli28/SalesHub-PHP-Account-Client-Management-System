import type { ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'

interface StatusPageProps {
  code?: string
  icon: LucideIcon
  title: string
  description: ReactNode
  actions?: ReactNode
}

/** Shared layout for 403, 404 and "coming soon" pages. */
export function StatusPage({ code, icon: Icon, title, description, actions }: StatusPageProps) {
  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center px-6 py-16 text-center">
      <div className="bg-primary/10 text-primary mb-6 flex size-14 items-center justify-center rounded-2xl">
        <Icon className="size-7" aria-hidden="true" />
      </div>
      {code ? <p className="text-primary text-sm font-semibold tracking-wide">{code}</p> : null}
      <h1 className="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">{title}</h1>
      <p className="text-muted-foreground mt-3 max-w-md">{description}</p>
      {actions ? <div className="mt-8 flex flex-wrap justify-center gap-3">{actions}</div> : null}
    </div>
  )
}
