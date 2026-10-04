import { ShieldXIcon } from 'lucide-react'
import { Link, useNavigate } from 'react-router'
import { Button } from '@/components/ui/button'
import { paths } from '../paths'
import { StatusPage } from './StatusPage'

export default function ForbiddenPage() {
  const navigate = useNavigate()
  return (
    <StatusPage
      code="403"
      icon={ShieldXIcon}
      title="You don't have access to this page"
      description="Your role doesn't include this area. If you need it for your work, ask an administrator to update your permissions."
      actions={
        <>
          <Button variant="outline" onClick={() => void navigate(-1)}>
            Go back
          </Button>
          <Button asChild>
            <Link to={paths.dashboard}>Go to the dashboard</Link>
          </Button>
        </>
      }
    />
  )
}
