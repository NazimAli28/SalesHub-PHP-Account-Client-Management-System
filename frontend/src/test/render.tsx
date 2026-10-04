import type { ReactElement } from 'react'
import { render } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient } from '@tanstack/react-query'
import { createMemoryRouter, type RouteObject } from 'react-router'
import { RouterProvider } from 'react-router/dom'
import type { Me } from '@/api/types'
import { AppProviders } from '@/app/AppProviders'
import { authKeys } from '@/features/auth/api'

export function createTestQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
  })
}

interface RenderOptions {
  /** Signed-in user (`null` = signed out). Seeds the `/auth/me` cache so no request is made. */
  user?: Me | null
  /** Initial URL, e.g. `/leads?page=2`. */
  route?: string
}

function renderRouter(routes: RouteObject[], options: RenderOptions) {
  const queryClient = createTestQueryClient()
  if (options.user !== undefined) queryClient.setQueryData(authKeys.me, options.user)
  const router = createMemoryRouter(routes, { initialEntries: [options.route ?? '/'] })
  const result = render(
    <AppProviders queryClient={queryClient}>
      <RouterProvider router={router} />
    </AppProviders>,
  )
  return { ...result, router, queryClient, user: userEvent.setup() }
}

/**
 * Renders `ui` inside the real app providers and an in-memory router. `extraRoutes` can stub
 * destinations such as `/403`. Returns `router` to assert on `router.state.location`.
 */
export function renderWithProviders(
  ui: ReactElement,
  options: RenderOptions & { path?: string; extraRoutes?: RouteObject[] } = {},
) {
  return renderRouter(
    [{ path: options.path ?? '*', element: ui }, ...(options.extraRoutes ?? [])],
    options,
  )
}

/** Renders a full route tree (e.g. the app's `routes`, lazy pages and guards included). */
export function renderRoutes(routes: RouteObject[], options: RenderOptions = {}) {
  return renderRouter(routes, options)
}
