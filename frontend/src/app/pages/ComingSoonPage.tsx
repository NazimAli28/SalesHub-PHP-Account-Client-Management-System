import { HammerIcon } from 'lucide-react'
import { Link, useMatches } from 'react-router'
import { Button } from '@/components/ui/button'
import { paths } from '../paths'
import type { RouteHandle } from '../router'
import { StatusPage } from './StatusPage'

/** Placeholder for modules that arrive in Phase 4. Title and description come from the route handle. */
export default function ComingSoonPage() {
  const matches = useMatches()
  const handle = matches[matches.length - 1]?.handle as RouteHandle | undefined
  const title = handle?.crumb ?? 'This module'

  return (
    <StatusPage
      icon={HammerIcon}
      title={`${title} is on its way`}
      description={
        <>
          {handle?.description ? `${handle.description} ` : null}
          This screen is being built in the next phase of SalesHub v2.
        </>
      }
      actions={
        <Button variant="outline" asChild>
          <Link to={paths.dashboard}>Back to the dashboard</Link>
        </Button>
      }
    />
  )
}
