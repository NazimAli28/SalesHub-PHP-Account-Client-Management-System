/**
 * The static browser demo (GitHub Pages build, `npm run build:demo`): the API runs in the browser
 * (src/demo, an MSW service worker) with sample data kept in sessionStorage.
 */
export const isStaticDemo = import.meta.env.VITE_STATIC_DEMO === 'true'

export const STATIC_DEMO_STORE_KEY = 'saleshub.static-demo.store.v1'
export const STATIC_DEMO_SESSION_KEY = 'saleshub.static-demo.session'

export const REPOSITORY_URL =
  'https://github.com/NazimAli28/SalesHub-PHP-Account-Client-Management-System'

/** Drops this tab's changes and reloads, so the demo starts again from the sample data. */
export function resetStaticDemoData(): void {
  try {
    window.sessionStorage.removeItem(STATIC_DEMO_STORE_KEY)
  } catch {
    // Blocked storage: nothing was saved, the reload alone resets the demo.
  }
  window.location.reload()
}
