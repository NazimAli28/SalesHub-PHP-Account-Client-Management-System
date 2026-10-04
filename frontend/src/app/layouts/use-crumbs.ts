import { useMatches } from 'react-router'
import type { RouteHandle } from '../router'

/** Breadcrumb built from the `handle.crumb` of each matched route. */
export function useCrumbs(): { label: string; to: string }[] {
  return useMatches()
    .filter((match) => (match.handle as RouteHandle | undefined)?.crumb)
    .map((match) => ({ label: (match.handle as RouteHandle).crumb, to: match.pathname }))
}
