/**
 * Boots the static browser demo (GitHub Pages build only, see main.tsx): loads the sample data,
 * then starts an MSW service worker that answers every `/api` and `/sanctum` request in the
 * browser, with the same envelopes, status codes, permissions and row scoping as the Laravel API.
 */
import { setupWorker } from 'msw/browser'
import dataUrl from './data/demo-data.json?url'
import { accountHandlers } from './handlers/accounts'
import { adminHandlers } from './handlers/admin'
import { authHandlers, DEMO_XSRF_TOKEN } from './handlers/auth'
import { clientHandlers } from './handlers/clients'
import { featureHandlers } from './handlers/features'
import { leadHandlers } from './handlers/leads'
import { orderHandlers } from './handlers/orders'
import { workflowHandlers } from './handlers/workflow'
import { initStore } from './store'
import type { DemoData } from './types'

export async function startStaticDemo(): Promise<void> {
  const response = await fetch(dataUrl)
  if (!response.ok) throw new Error(`Could not load the demo data (${response.status}).`)
  initStore((await response.json()) as DemoData)

  // The SPA reads the CSRF cookie before writes; any value works here.
  document.cookie = `XSRF-TOKEN=${encodeURIComponent(DEMO_XSRF_TOKEN)}; path=/; SameSite=Lax`

  const worker = setupWorker(
    ...authHandlers,
    // Specific paths before their `:id` siblings (e.g. /approvals/pending-count).
    ...workflowHandlers,
    ...leadHandlers,
    ...clientHandlers,
    ...orderHandlers,
    ...accountHandlers,
    ...adminHandlers,
    ...featureHandlers,
  )

  await worker.start({
    serviceWorker: { url: `${import.meta.env.BASE_URL}mockServiceWorker.js` },
    onUnhandledRequest: 'bypass',
    quiet: true,
  })
}
