import type { ReactNode } from 'react'
import { Card, CardContent } from '@/components/ui/card'
import { cn } from '@/lib/utils'

interface KpiCardProps {
  label: string
  /** The headline figure (text, number or a `<MoneyText>`). */
  value: ReactNode
  hint?: ReactNode
  /** Draws attention (e.g. overdue payments). */
  tone?: 'default' | 'danger'
  className?: string
}

/** A small figure card for the top of a detail page. */
export function KpiCard({ label, value, hint, tone = 'default', className }: KpiCardProps) {
  return (
    <Card className={cn(tone === 'danger' && 'border-destructive/40 bg-destructive/5', className)}>
      <CardContent className="space-y-1">
        <p className="text-muted-foreground text-sm">{label}</p>
        <p
          className={cn(
            'text-2xl font-semibold tracking-tight',
            tone === 'danger' && 'text-destructive',
          )}
        >
          {value}
        </p>
        {hint ? <p className="text-muted-foreground text-xs">{hint}</p> : null}
      </CardContent>
    </Card>
  )
}
