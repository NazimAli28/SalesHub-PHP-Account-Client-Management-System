import { isRouteErrorResponse, Link, useRouteError } from 'react-router'
import { isApiError } from '@/api/errors'
import { ErrorState } from '@/components/layout/ErrorState'
import { Button } from '@/components/ui/button'
import { paths } from '../paths'

/** Lazy chunks go missing after a deploy (their hashed names change); a reload fixes it. */
function isChunkLoadError(error: unknown): boolean {
  return (
    error instanceof Error &&
    /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module/i.test(
      error.message,
    )
  )
}

/**
 * Error boundary for every route: a crash in one page shows this inside the app shell instead
 * of a blank screen, and the rest of the app keeps working.
 */
export function RouteErrorBoundary() {
  const error = useRouteError()

  if (isChunkLoadError(error)) {
    return (
      <div className="flex min-h-[50vh] items-center justify-center p-6">
        <ErrorState
          title="A new version of SalesHub is available"
          error={new Error('Reload the page to continue.')}
          onRetry={() => window.location.reload()}
        />
      </div>
    )
  }

  const status = isRouteErrorResponse(error)
    ? error.status
    : isApiError(error)
      ? error.status
      : undefined

  return (
    <div className="flex min-h-[50vh] flex-col items-center justify-center gap-2 p-6">
      <ErrorState
        title={status === 404 ? 'Page not found' : 'This page ran into a problem'}
        error={isRouteErrorResponse(error) ? new Error(error.statusText) : error}
        onRetry={() => window.location.reload()}
      />
      <Button variant="link" asChild>
        <Link to={paths.dashboard}>Back to the dashboard</Link>
      </Button>
    </div>
  )
}
