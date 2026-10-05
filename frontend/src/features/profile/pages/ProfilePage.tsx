import type { ReactNode } from 'react'
import { PageHeader } from '@/components/layout/PageHeader'
import { RelativeTime } from '@/components/data-display/RelativeTime'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { accountSecurity } from '@/features/auth/api'
import { useAuth } from '@/features/auth/AuthProvider'
import { initials } from '@/lib/format'
import { roleLabel } from '@/lib/roles'
import { ChangePasswordForm } from '../components/ChangePasswordForm'
import { DemoModeNotice } from '../components/DemoModeNotice'
import { SessionsCard } from '../components/SessionsCard'
import { TwoFactorCard } from '../components/TwoFactorCard'

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-[8rem_1fr] gap-2 py-2 text-sm">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="min-w-0 truncate">{children}</dd>
    </div>
  )
}

export default function ProfilePage() {
  const { user } = useAuth()
  if (!user) return null
  const { twoFactorEnabled, demoMode } = accountSecurity(user)

  return (
    <div className="space-y-6">
      <PageHeader title="Profile" description="Your account details and security settings." />

      <div className="grid gap-6 lg:grid-cols-[minmax(0,22rem)_1fr]">
        <Card>
          <CardHeader className="flex flex-row items-center gap-4">
            <Avatar className="size-14">
              {user.avatar_url ? <AvatarImage src={user.avatar_url} alt="" /> : null}
              <AvatarFallback className="bg-primary/10 text-primary text-lg font-semibold">
                {initials(user.name)}
              </AvatarFallback>
            </Avatar>
            <div className="min-w-0 space-y-1">
              <CardTitle className="truncate">{user.name}</CardTitle>
              <div className="flex flex-wrap gap-1">
                {user.roles.map((role) => (
                  <Badge key={role} variant="secondary">
                    {roleLabel(role)}
                  </Badge>
                ))}
              </div>
            </div>
          </CardHeader>
          <CardContent>
            <dl className="divide-y">
              <Detail label="Username">{user.username ?? '—'}</Detail>
              <Detail label="Email">{user.email}</Detail>
              <Detail label="Team">
                {user.team ? `${user.team.name} · floor ${user.team.floor}` : '—'}
              </Detail>
              <Detail label="Workstation">{user.workstation?.code ?? '—'}</Detail>
              <Detail label="Last sign-in">
                <RelativeTime value={user.last_login_at} />
              </Detail>
            </dl>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Change password</CardTitle>
            <CardDescription>
              After you change it, every other device signed in to your account is signed out.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ChangePasswordForm disabled={demoMode} />
          </CardContent>
        </Card>
      </div>

      <section aria-labelledby="security-title" className="space-y-4">
        <div className="space-y-1">
          <h2 id="security-title" className="text-lg font-semibold tracking-tight">
            Security
          </h2>
          <p className="text-muted-foreground text-sm">
            Two-step verification and the browsers signed in to your account.
          </p>
        </div>
        {demoMode ? <DemoModeNotice /> : null}
        <div className="grid gap-6 lg:grid-cols-2">
          <TwoFactorCard enabled={twoFactorEnabled} demoMode={demoMode} />
          <SessionsCard demoMode={demoMode} />
        </div>
      </section>
    </div>
  )
}
