import {
  ArrowRightIcon,
  BarChart3Icon,
  ClipboardCheckIcon,
  SparklesIcon,
  TargetIcon,
} from 'lucide-react'
import { Link } from 'react-router'
import { paths } from '@/app/paths'
import { PageHeader } from '@/components/layout/PageHeader'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { useAuth } from '@/features/auth/AuthProvider'
import { Can } from '@/features/auth/Can'
import { formatRelative } from '@/lib/format'
import { VIEW_ANY } from '@/lib/permissions'
import { roleLabel } from '@/lib/roles'

function greeting(date = new Date()): string {
  const hour = date.getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
}

const UPCOMING = [
  {
    icon: BarChart3Icon,
    title: 'Pipeline and revenue charts',
    text: 'Stage conversion, won value and payments due, per team and per person.',
  },
  {
    icon: ClipboardCheckIcon,
    title: 'Approvals at a glance',
    text: 'What is waiting for you, and what you have sent for review.',
  },
  {
    icon: TargetIcon,
    title: 'Follow-ups due today',
    text: 'Leads whose next follow-up date has arrived.',
  },
]

export default function DashboardPage() {
  const { user } = useAuth()
  if (!user) return null
  const firstName = user.name.split(' ')[0]

  return (
    <div className="space-y-8">
      <PageHeader
        title={`${greeting()}, ${firstName}`}
        description={
          <>
            Signed in as {user.roles.map(roleLabel).join(', ')}
            {user.team ? ` on ${user.team.name}` : ''}
            {user.last_login_at ? ` · last sign-in ${formatRelative(user.last_login_at)}` : ''}
          </>
        }
      />

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <Badge variant="secondary" className="mb-2 w-fit">
              <SparklesIcon aria-hidden="true" />
              Coming in Phase 5
            </Badge>
            <CardTitle className="text-lg">Your dashboard is being built</CardTitle>
            <CardDescription>
              The foundation is in place: sign-in, roles and permissions, navigation and the shared
              table and form components. Live metrics land here next.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ul className="grid gap-4 sm:grid-cols-3">
              {UPCOMING.map(({ icon: Icon, title, text }) => (
                <li key={title} className="bg-muted/40 space-y-2 rounded-lg border p-4">
                  <Icon className="text-primary size-5" aria-hidden="true" />
                  <p className="text-sm font-medium">{title}</p>
                  <p className="text-muted-foreground text-sm">{text}</p>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>

        <Can anyOf={VIEW_ANY.leads}>
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">Work your leads</CardTitle>
              <CardDescription>
                The Leads list is live: filter by stage or owner, search, sort and page through your
                pipeline.
              </CardDescription>
            </CardHeader>
            <CardFooter className="mt-auto">
              <Button asChild>
                <Link to={paths.leads}>
                  Open leads
                  <ArrowRightIcon aria-hidden="true" />
                </Link>
              </Button>
            </CardFooter>
          </Card>
        </Can>
      </div>
    </div>
  )
}
