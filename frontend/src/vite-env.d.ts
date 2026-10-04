/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** API origin when it differs from the app's (leave empty in dev: Vite proxies /api). */
  readonly VITE_API_URL?: string
  /** `'true'` shows the demo quick-login buttons on the sign-in page. */
  readonly VITE_DEMO_MODE?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
