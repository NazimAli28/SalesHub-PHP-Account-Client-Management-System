import { CompassIcon } from 'lucide-react'
import { Link } from 'react-router'
import { Button } from '@/components/ui/button'
import { paths } from '../paths'
import { StatusPage } from './StatusPage'

export default function NotFoundPage() {
  return (
    <StatusPage
      code="404"
      icon={CompassIcon}
      title="We couldn't find that page"
      description="The link may be broken or the page may have moved. Check the address, or head back to the dashboard."
      actions={
        <Button asChild>
          <Link to={paths.dashboard}>Go to the dashboard</Link>
        </Button>
      }
    />
  )
}
