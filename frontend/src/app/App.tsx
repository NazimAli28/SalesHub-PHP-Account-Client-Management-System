import { useEffect, useState } from 'react'
import { createBrowserRouter } from 'react-router'
import { RouterProvider } from 'react-router/dom'
import { onUnauthorized } from '@/api/client'
import { resetSession } from '@/features/auth/api'
import { loginPathFor } from '@/features/auth/redirect'
import { AppProviders } from './AppProviders'
import { paths } from './paths'
import { createQueryClient } from './query-client'
import { routes } from './router'

export function App() {
  const [queryClient] = useState(createQueryClient)
  const [router] = useState(() => createBrowserRouter(routes))

  // Global 401 handling: the session ended (expired, signed out elsewhere, deactivated).
  // Forget cached data, mark the user as signed out and send them to /login?redirect=...
  useEffect(
    () =>
      onUnauthorized(() => {
        resetSession(queryClient, null)
        const location = router.state.location
        if (!location.pathname.startsWith(paths.login)) {
          void router.navigate(loginPathFor(location), { replace: true })
        }
      }),
    [queryClient, router],
  )

  return (
    <AppProviders queryClient={queryClient}>
      <RouterProvider router={router} />
    </AppProviders>
  )
}
